<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Repository\CardLoadFailure;

/**
 * Verifies a {@see Board} against its {@see BoardConfig} and produces a
 * structured {@see VerificationReport}. Never writes to STDOUT/STDERR and
 * never throws for a violation — only the CLI decides how to present a
 * report and which exit code to use.
 *
 * `docs/card-format.md` and `docs/configuration.md` document every rule
 * enforced here.
 */
final class BoardVerifier
{
    /**
     * @param list<CardLoadFailure> $loadFailures Per-file parse failures from
     *                                            {@see \voku\AgentKanban\Repository\MarkdownCardRepository::loadAllLenient()}.
     */
    public function verify(
        Board $board,
        array $loadFailures = [],
        ?BoardVerificationContext $context = null,
    ): VerificationReport {
        $context ??= new BoardVerificationContext();
        $violations = [];

        foreach ($loadFailures as $failure) {
            $violations[] = $this->violationForLoadFailure($failure);
        }

        foreach ($board->cards->all() as $card) {
            array_push($violations, ...$this->verifyCard($card, $board->config));
        }

        array_push($violations, ...$this->verifyWipLimits($board));
        array_push($violations, ...$this->verifyContext($board, $context));

        return new VerificationReport($violations);
    }

    private function violationForLoadFailure(CardLoadFailure $failure): Violation
    {
        $code = ViolationCode::MalformedMetadata;
        if (str_contains($failure->message, 'Duplicate metadata field')) {
            $code = ViolationCode::DuplicateMetadataField;
        } elseif (str_contains($failure->message, 'Duplicate card ID')) {
            $code = ViolationCode::DuplicateCardId;
        }

        return new Violation(
            $code,
            $failure->message,
            Severity::Error,
            $failure->cardId,
            $failure->field,
            $failure->file,
        );
    }

    /**
     * @return list<Violation>
     */
    private function verifyCard(Card $card, BoardConfig $config): array
    {
        $violations = [];
        $id = $card->id->toString();

        $expectedFilename = $id . '.md';
        if (basename($card->sourceFile) !== $expectedFilename) {
            $violations[] = new Violation(
                ViolationCode::InvalidFilename,
                sprintf('Card %s is stored in "%s", expected filename "%s".', $id, $card->sourceFile, $expectedFilename),
                Severity::Error,
                $id,
                null,
                $card->sourceFile,
            );
        }

        if ($card->id->prefix !== $config->projectPrefix) {
            $violations[] = new Violation(
                ViolationCode::InvalidProjectPrefix,
                sprintf('Card %s has prefix "%s", expected "%s".', $id, $card->id->prefix, $config->projectPrefix),
                Severity::Error,
                $id,
                'id',
                $card->sourceFile,
            );
        }

        if (!$config->supportsLane($card->lane)) {
            $violations[] = new Violation(
                ViolationCode::UnsupportedLane,
                sprintf('Card %s is in unsupported lane "%s".', $id, $card->lane),
                Severity::Error,
                $id,
                'lane',
                $card->sourceFile,
            );
        }

        $allowedStatuses = $config->statusToLane[$card->lane->toString()] ?? null;
        if ($allowedStatuses !== null && !in_array($card->status->toString(), $allowedStatuses, true)) {
            $violations[] = new Violation(
                ViolationCode::InvalidStatusLaneMapping,
                sprintf('Card %s has status "%s" which is not allowed in lane %s.', $id, $card->status, $card->lane),
                Severity::Error,
                $id,
                'status',
                $card->sourceFile,
            );
        }

        foreach ($config->requiredFieldsByLane[$card->lane->toString()] ?? [] as $field) {
            if ($this->fieldIsEmpty($card, $field)) {
                $violations[] = new Violation(
                    $field === 'taskBrief' ? ViolationCode::MissingTaskBrief : ViolationCode::MissingRequiredField,
                    sprintf('Card %s is missing required field "%s" for lane %s.', $id, $field, $card->lane),
                    Severity::Error,
                    $id,
                    $field,
                    $card->sourceFile,
                );
            }
        }

        if ($card->createdAtRaw !== '' && $card->createdAt === null) {
            $violations[] = new Violation(
                ViolationCode::InvalidTimestamp,
                sprintf('Card %s has an unparseable "Created" timestamp: "%s".', $id, $card->createdAtRaw),
                Severity::Error,
                $id,
                'Created',
                $card->sourceFile,
            );
        }

        if ($card->updatedAtRaw !== '' && $card->updatedAt === null) {
            $violations[] = new Violation(
                ViolationCode::InvalidTimestamp,
                sprintf('Card %s has an unparseable "Updated" timestamp: "%s".', $id, $card->updatedAtRaw),
                Severity::Error,
                $id,
                'Updated',
                $card->sourceFile,
            );
        }

        if ($card->claim !== null && $card->claim->expiresAt !== null && $card->claim->expiresAt < $card->claim->claimedAt) {
            $violations[] = new Violation(
                ViolationCode::InvalidClaim,
                sprintf('Card %s has a claim that expires before it was claimed.', $id),
                Severity::Error,
                $id,
                'Claim',
                $card->sourceFile,
            );
        }

        if (!$this->laneParticipatesInTransitions($card->lane->toString(), $config)) {
            $violations[] = new Violation(
                ViolationCode::InvalidTransitionState,
                sprintf('Card %s is in lane "%s", which has no configured transitions in or out.', $id, $card->lane),
                Severity::Warning,
                $id,
                'lane',
                $card->sourceFile,
            );
        }

        if ($card->formatVersion > $config->formatVersion) {
            $violations[] = new Violation(
                ViolationCode::IncompatibleFormatVersion,
                sprintf('Card %s declares format version %d, newer than the supported %d.', $id, $card->formatVersion, $config->formatVersion),
                Severity::Error,
                $id,
                'Format version',
                $card->sourceFile,
            );
        } elseif ($card->formatVersion < $config->formatVersion) {
            $violations[] = new Violation(
                ViolationCode::StaleFormatVersion,
                sprintf('Card %s declares format version %d, older than the current %d.', $id, $card->formatVersion, $config->formatVersion),
                Severity::Warning,
                $id,
                'Format version',
                $card->sourceFile,
            );
        }

        return $violations;
    }

    private function fieldIsEmpty(Card $card, string $field): bool
    {
        return match ($field) {
            'summary'    => $card->summary === '',
            'taskBrief'  => $card->taskBrief === '',
            'assignee'   => $card->assignee === null || $card->assignee === '',
            'domain'     => $card->domain === null || $card->domain === '',
            'nextAction' => $card->nextAction === '',
            'validation' => $card->validation === '',
            'wave'       => $card->wave === '',
            default      => false,
        };
    }

    private function laneParticipatesInTransitions(string $lane, BoardConfig $config): bool
    {
        if (array_key_exists($lane, $config->transitions)) {
            return true;
        }

        foreach ($config->transitions as $targets) {
            if (in_array($lane, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Violation>
     */
    private function verifyWipLimits(Board $board): array
    {
        $violations = [];
        $wipHealth = (new BoardQueryService($board))->wipHealth();

        foreach ($wipHealth->groups as $group) {
            if ($group->isOverLimit()) {
                $violations[] = new Violation(
                    ViolationCode::InvalidWipCount,
                    sprintf('WIP group "%s" has %d cards, over the configured limit of %d.', $group->group, $group->count, $group->limit),
                    Severity::Error,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function verifyContext(Board $board, BoardVerificationContext $context): array
    {
        $violations = [];

        if ($context->bothCardDirectoriesExist) {
            $violations[] = new Violation(
                ViolationCode::SourceDirectoryAmbiguity,
                sprintf(
                    'Both "%s" and "%s" exist; "%s" takes precedence and "%s" is ignored. Consider migrating fully.',
                    $board->config->cardDirectory,
                    $board->config->legacyCardDirectory,
                    $board->config->cardDirectory,
                    $board->config->legacyCardDirectory,
                ),
                Severity::Warning,
            );
        }

        foreach ($context->archivedCardIds as $archivedId) {
            if ($board->cards->has($archivedId)) {
                $violations[] = new Violation(
                    ViolationCode::ArchiveConflict,
                    sprintf('Card %s exists in both the active card directory and the archive.', $archivedId),
                    Severity::Error,
                    $archivedId,
                );
            }
        }

        if ($context->boardMetadata !== null) {
            $metadataPrefix = $context->boardMetadata->projectPrefix;
            if ($metadataPrefix !== null && $metadataPrefix !== $board->config->projectPrefix) {
                $violations[] = new Violation(
                    ViolationCode::BoardMetadataInconsistency,
                    sprintf(
                        'todo/board.md declares project prefix "%s", but the board is configured for "%s".',
                        $metadataPrefix,
                        $board->config->projectPrefix,
                    ),
                    Severity::Error,
                );
            }
        }

        if ($context->indexContent !== null && $context->cardDirectory !== null) {
            if (!str_contains($context->indexContent, $context->cardDirectory . '/')) {
                $violations[] = new Violation(
                    ViolationCode::BoardMetadataInconsistency,
                    sprintf('TODO.md does not reference the active card directory "%s/".', $context->cardDirectory),
                    Severity::Error,
                );
            }
        }

        return $violations;
    }
}
