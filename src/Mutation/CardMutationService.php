<?php

declare(strict_types=1);

namespace voku\AgentKanban\Mutation;

use DateTimeImmutable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Claim;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Exception\NotFoundException;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\CardParser;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Transition\TransitionPolicy;
use voku\AgentKanban\Transition\TransitionResult;

final class CardMutationService
{
    private readonly TransitionPolicy $transitionPolicy;
    private readonly CardParser $parser;

    public function __construct(
        private readonly string $rootPath,
        private readonly BoardConfig $config,
        private readonly MarkdownCardRepository $repository,
        ?TransitionPolicy $transitionPolicy = null,
    ) {
        $this->transitionPolicy = $transitionPolicy ?? new TransitionPolicy($config);
        $this->parser = new CardParser();
    }

    public function create(
        CardId $id,
        Lane $lane,
        CardStatus $status,
        string $title,
        string $summary = '',
        bool $dryRun = false,
    ): MutationResult {
        if (!$this->config->supportsLane($lane)) {
            throw new ValidationException(sprintf('Unsupported lane "%s".', $lane), field: 'lane', cardId: $id->toString());
        }

        $now = new DateTimeImmutable();
        $card = new Card(
            id: $id,
            title: $title !== '' ? $title : $id->toString(),
            lane: $lane,
            status: $status,
            domain: null,
            assignee: null,
            createdAt: $now,
            createdAtRaw: $now->format(DATE_ATOM),
            updatedAt: $now,
            updatedAtRaw: $now->format(DATE_ATOM),
            summary: $summary,
            nextAction: '',
            validation: '',
            priority: null,
            wave: '',
            taskBrief: '',
            handoffNotes: '',
            claim: null,
            externalIssue: null,
            formatVersion: $this->config->formatVersion,
            extensionFields: [],
            extraSectionsRaw: '',
            revision: CardRevision::fromContent(''),
            sourceFile: '',
        );

        $serialized = $this->repository->serialize($card);
        $revision = CardRevision::fromContent($serialized);
        $path = $this->repository->pathForNewCard($id);
        $card = $this->withRevisionAndSource($card, $revision, $path);

        if (!$dryRun) {
            $this->repository->atomicWrite($path, $serialized, mustNotExist: true);
        }

        return new MutationResult('create', $card, null, $revision, $dryRun, [], ['*'], $now);
    }

    public function update(
        CardId $id,
        ?string $title = null,
        ?CardStatus $status = null,
        ?string $domain = null,
        ?string $assignee = null,
        ?string $summary = null,
        ?string $nextAction = null,
        ?string $validation = null,
        ?int $priority = null,
        ?string $wave = null,
        ?string $taskBrief = null,
        ?string $handoffNotes = null,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        [$current, $currentRevision] = $this->loadCurrent($id);
        $this->assertRevision($id, $currentRevision, $expectedRevision);

        $changed = [];
        $this->recordChange($changed, 'title', $title, $current->title);
        $this->recordChange($changed, 'status', $status?->toString(), $current->status->toString());
        $this->recordChange($changed, 'domain', $domain, $current->domain);
        $this->recordChange($changed, 'assignee', $assignee, $current->assignee);
        $this->recordChange($changed, 'summary', $summary, $current->summary);
        $this->recordChange($changed, 'nextAction', $nextAction, $current->nextAction);
        $this->recordChange($changed, 'validation', $validation, $current->validation);
        $this->recordChange($changed, 'priority', $priority, $current->priority);
        $this->recordChange($changed, 'wave', $wave, $current->wave);
        $this->recordChange($changed, 'taskBrief', $taskBrief, $current->taskBrief);
        $this->recordChange($changed, 'handoffNotes', $handoffNotes, $current->handoffNotes);

        $now = new DateTimeImmutable();
        $updated = $current->with(
            title: $title,
            status: $status,
            domain: $domain,
            assignee: $assignee,
            updatedAt: $now,
            summary: $summary,
            nextAction: $nextAction,
            validation: $validation,
            priority: $priority,
            wave: $wave,
            taskBrief: $taskBrief,
            handoffNotes: $handoffNotes,
        );

        return $this->writeUpdatedCard('update', $current, $updated, $currentRevision, $dryRun, [], $changed, $now);
    }

    public function move(
        CardId $id,
        Lane $to,
        ?string $actor = null,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        [$current, $currentRevision] = $this->loadCurrent($id);
        $this->assertRevision($id, $currentRevision, $expectedRevision);
        $this->transitionPolicy->validate($current->lane, $to);

        $now = new DateTimeImmutable();
        $result = $this->writeUpdatedCard(
            'move',
            $current,
            $current->with(lane: $to, updatedAt: $now),
            $currentRevision,
            $dryRun,
            [],
            ['lane'],
            $now,
        );

        $transition = new TransitionResult(
            $current->lane,
            $to,
            $currentRevision,
            $result->newRevision,
            $actor,
            $now,
            $result->warnings,
            ['lane'],
        );

        return new MutationResult(
            $result->operation,
            $result->card,
            $result->previousRevision,
            $result->newRevision,
            $result->dryRun,
            $result->warnings,
            $result->changedFields,
            $result->timestamp,
            $transition,
        );
    }

    public function claim(
        CardId $id,
        string $actor,
        ?DateTimeImmutable $expiresAt = null,
        bool $moveToDoing = false,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        [$current, $currentRevision] = $this->loadCurrent($id);
        $this->assertRevision($id, $currentRevision, $expectedRevision);

        $now = new DateTimeImmutable();
        if ($current->claim !== null && !$current->claim->isExpired($now) && $current->claim->actor !== $actor) {
            throw new ConflictException(
                sprintf('Card %s is already claimed by "%s".', $id, $current->claim->actor),
                cardId: $id->toString(),
            );
        }

        $lane = $current->lane;
        $warnings = [];
        $changedFields = ['claim'];
        if ($moveToDoing) {
            $doing = Lane::fromString('DOING');
            if ($this->transitionPolicy->canTransition($current->lane, $doing)) {
                $lane = $doing;
                $changedFields[] = 'lane';
            } else {
                $warnings[] = sprintf(
                    'Card %s could not move to DOING on claim: no configured transition from %s.',
                    $id,
                    $current->lane,
                );
            }
        }

        $claim = new Claim($actor, $now, $expiresAt, $currentRevision);
        $updated = $current->with(lane: $lane, claim: $claim, updatedAt: $now);

        return $this->writeUpdatedCard(
            'claim',
            $current,
            $updated,
            $currentRevision,
            $dryRun,
            $warnings,
            $changedFields,
            $now,
        );
    }

    public function release(
        CardId $id,
        string $actor,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        [$current, $currentRevision] = $this->loadCurrent($id);
        $this->assertRevision($id, $currentRevision, $expectedRevision);

        if ($current->claim === null) {
            throw new ValidationException(sprintf('Card %s is not claimed.', $id), field: 'claim', cardId: $id->toString());
        }
        if ($current->claim->actor !== $actor) {
            throw new ConflictException(
                sprintf('Card %s is claimed by "%s", not "%s".', $id, $current->claim->actor, $actor),
                cardId: $id->toString(),
            );
        }

        $now = new DateTimeImmutable();

        return $this->writeUpdatedCard(
            'release',
            $current,
            $current->with(clearClaim: true, updatedAt: $now),
            $currentRevision,
            $dryRun,
            [],
            ['claim'],
            $now,
        );
    }

    public function archive(
        CardId $id,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        if ($this->config->archiveDirectory === null) {
            throw new ConfigurationException('No archiveDirectory is configured for this board.');
        }

        [$current, $currentRevision] = $this->loadCurrent($id);
        $this->assertRevision($id, $currentRevision, $expectedRevision);
        $source = $this->repository->findExistingPath($id)
            ?? throw new NotFoundException(sprintf('Card not found: %s', $id), cardId: $id->toString());
        $destination = $this->rootPath . '/' . $this->config->archiveDirectory . '/' . $id->toString() . '.md';

        if (!$dryRun) {
            $this->repository->moveFile($source, $destination, $currentRevision);
        }

        $now = new DateTimeImmutable();
        $card = $this->withRevisionAndSource($current, $currentRevision, $destination);

        return new MutationResult('archive', $card, $currentRevision, $currentRevision, $dryRun, [], ['*'], $now);
    }

    public function restore(
        CardId $id,
        ?CardRevision $expectedRevision = null,
        bool $dryRun = false,
    ): MutationResult {
        if ($this->config->archiveDirectory === null) {
            throw new ConfigurationException('No archiveDirectory is configured for this board.');
        }

        $archivedPath = $this->rootPath . '/' . $this->config->archiveDirectory . '/' . $id->toString() . '.md';
        if (!is_file($archivedPath)) {
            throw new NotFoundException(sprintf('Card %s is not in the archive.', $id), cardId: $id->toString());
        }

        $current = $this->parser->parse($this->repository->readRaw($archivedPath), $this->relativePath($archivedPath), $id->toString());
        $currentRevision = $current->revision;
        $this->assertRevision($id, $currentRevision, $expectedRevision);
        $newPath = $this->repository->pathForNewCard($id);

        if (!$dryRun) {
            $this->repository->moveFile($archivedPath, $newPath, $currentRevision);
        }

        $now = new DateTimeImmutable();
        $card = $this->withRevisionAndSource($current, $currentRevision, $newPath);

        return new MutationResult('restore', $card, $currentRevision, $currentRevision, $dryRun, [], ['*'], $now);
    }

    /** @return array{0: Card, 1: CardRevision} */
    private function loadCurrent(CardId $id): array
    {
        $card = $this->repository->load($id);

        return [$card, $card->revision];
    }

    private function assertRevision(CardId $id, CardRevision $actual, ?CardRevision $expected): void
    {
        if ($expected !== null && !$expected->equals($actual)) {
            throw new ConflictException(
                sprintf('Card %s has revision %s, expected %s.', $id, $actual, $expected),
                cardId: $id->toString(),
                expectedRevision: $expected->toString(),
                actualRevision: $actual->toString(),
            );
        }
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $changedFields
     */
    private function writeUpdatedCard(
        string $operation,
        Card $current,
        Card $updated,
        CardRevision $previousRevision,
        bool $dryRun,
        array $warnings,
        array $changedFields,
        DateTimeImmutable $timestamp,
    ): MutationResult {
        $path = $this->repository->findExistingPath($current->id) ?? $this->repository->pathForNewCard($current->id);
        $serialized = $this->repository->serialize($updated);
        $newRevision = CardRevision::fromContent($serialized);
        $finalCard = $this->withRevisionAndSource($updated, $newRevision, $path);

        if (!$dryRun) {
            $this->repository->atomicWrite($path, $serialized, $previousRevision);
        }

        return new MutationResult(
            $operation,
            $finalCard,
            $previousRevision,
            $newRevision,
            $dryRun,
            $warnings,
            $changedFields,
            $timestamp,
        );
    }

    private function withRevisionAndSource(Card $card, CardRevision $revision, string $sourceFile): Card
    {
        return new Card(
            id: $card->id,
            title: $card->title,
            lane: $card->lane,
            status: $card->status,
            domain: $card->domain,
            assignee: $card->assignee,
            createdAt: $card->createdAt,
            createdAtRaw: $card->createdAtRaw,
            updatedAt: $card->updatedAt,
            updatedAtRaw: $card->updatedAtRaw,
            summary: $card->summary,
            nextAction: $card->nextAction,
            validation: $card->validation,
            priority: $card->priority,
            wave: $card->wave,
            taskBrief: $card->taskBrief,
            handoffNotes: $card->handoffNotes,
            claim: $card->claim,
            externalIssue: $card->externalIssue,
            formatVersion: $card->formatVersion,
            extensionFields: $card->extensionFields,
            extraSectionsRaw: $card->extraSectionsRaw,
            revision: $revision,
            sourceFile: $this->relativePath($sourceFile),
        );
    }

    /** @param list<string> $changed */
    private function recordChange(
        array &$changed,
        string $field,
        string|int|null $newValue,
        string|int|null $currentValue,
    ): void {
        if ($newValue !== null && $newValue !== $currentValue) {
            $changed[] = $field;
        }
    }

    private function relativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->rootPath . '/')) {
            return substr($absolutePath, strlen($this->rootPath) + 1);
        }

        return $absolutePath;
    }
}
