<?php

declare(strict_types=1);

namespace voku\AgentKanban\Rendering;

use voku\AgentKanban\Board;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Verification\VerificationReport;

/**
 * Renders board data to versioned, stable JSON shapes. Every document this
 * class produces carries a top-level `"schemaVersion"` so consumers (CLI
 * scripts, `voku/agent-loop`, CI) can detect a breaking shape change instead
 * of guessing. See `docs/json-format.md` for the full, worked-example
 * specification of every shape below.
 *
 * Never includes exception traces or other unbounded diagnostic detail —
 * only the fields documented in the spec.
 *
 * @phpstan-type CardArray array{
 *     id: string,
 *     title: string,
 *     lane: string,
 *     status: string,
 *     domain: string|null,
 *     assignee: string|null,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     summary: string,
 *     nextAction: string,
 *     validation: string,
 *     priority: int|null,
 *     wave: string,
 *     taskBrief: string,
 *     handoffNotes: string,
 *     claim: array{actor: string, claimedAt: string, expiresAt: string|null, revisionAtClaim: string}|null,
 *     externalIssue: array{system: string, key: string}|null,
 *     formatVersion: int,
 *     extensionFields: array<string, string>,
 *     revision: string,
 *     sourceFile: string
 * }
 */
final class JsonBoardRenderer
{
    public const int SCHEMA_VERSION = 1;

    public function encode(mixed $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $json . "\n";
    }

    /**
     * @return array{schemaVersion: int, type: string, generatedAt: string, projectPrefix: string, laneCounts: array<string, int>, totalCards: int, doneCount: int, formatVersion: int}
     */
    public function summaryToArray(Board $board): array
    {
        $summary = (new BoardQueryService($board))->summary();

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'type'          => 'board-summary',
            'generatedAt'   => $this->now(),
            'projectPrefix' => $board->config->projectPrefix,
            'laneCounts'    => $summary->laneCounts,
            'totalCards'    => $summary->totalCards,
            'doneCount'     => $summary->doneCount,
            'formatVersion' => $summary->formatVersion,
        ];
    }

    /**
     * @return array{schemaVersion: int, type: string, generatedAt: string, card: CardArray}
     */
    public function cardToEnvelope(Card $card): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'type'          => 'card',
            'generatedAt'   => $this->now(),
            'card'          => $this->cardToArray($card),
        ];
    }

    /**
     * @param list<Card> $cards
     *
     * @return array{schemaVersion: int, type: string, generatedAt: string, count: int, cards: list<CardArray>}
     */
    public function cardsToEnvelope(array $cards): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'type'          => 'card-list',
            'generatedAt'   => $this->now(),
            'count'         => count($cards),
            'cards'         => array_map($this->cardToArray(...), $cards),
        ];
    }

    /**
     * @return CardArray
     */
    public function cardToArray(Card $card): array
    {
        return [
            'id'              => $card->id->toString(),
            'title'           => $card->title,
            'lane'            => $card->lane->toString(),
            'status'          => $card->status->toString(),
            'domain'          => $card->domain,
            'assignee'        => $card->assignee,
            'createdAt'       => $card->createdAt?->format('Y-m-d\TH:i:sP'),
            'updatedAt'       => $card->updatedAt?->format('Y-m-d\TH:i:sP'),
            'summary'         => $card->summary,
            'nextAction'      => $card->nextAction,
            'validation'      => $card->validation,
            'priority'        => $card->priority,
            'wave'            => $card->wave,
            'taskBrief'       => $card->taskBrief,
            'handoffNotes'    => $card->handoffNotes,
            'claim'           => $card->claim === null ? null : [
                'actor'           => $card->claim->actor,
                'claimedAt'       => $card->claim->claimedAt->format('Y-m-d\TH:i:sP'),
                'expiresAt'       => $card->claim->expiresAt?->format('Y-m-d\TH:i:sP'),
                'revisionAtClaim' => $card->claim->revisionAtClaim->toString(),
            ],
            'externalIssue'   => $card->externalIssue === null ? null : [
                'system' => $card->externalIssue->system,
                'key'    => $card->externalIssue->key,
            ],
            'formatVersion'   => $card->formatVersion,
            'extensionFields' => $card->extensionFields,
            'revision'        => $card->revision->toString(),
            'sourceFile'      => $card->sourceFile,
        ];
    }

    /**
     * @return array{schemaVersion: int, type: string, generatedAt: string, isValid: bool, violations: list<array{code: string, message: string, severity: string, cardId: string|null, field: string|null, file: string|null}>}
     */
    public function verificationReportToArray(VerificationReport $report): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'type'          => 'verification-report',
            'generatedAt'   => $this->now(),
            'isValid'       => $report->isValid(),
            'violations'    => array_map(
                static fn ($violation): array => $violation->toArray(),
                $report->violations,
            ),
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }
}
