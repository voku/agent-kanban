<?php

declare(strict_types=1);

namespace voku\AgentKanban\Mutation;

use DateTimeImmutable;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Transition\TransitionResult;

/**
 * The outcome of a successful (or successfully dry-run-previewed) mutation.
 * Failures never produce a MutationResult — they throw
 * {@see \voku\AgentKanban\Exception\ConflictException} or
 * {@see \voku\AgentKanban\Exception\ValidationException} instead, so a
 * MutationResult is always good news, real or hypothetical.
 */
final readonly class MutationResult
{
    /**
     * @param list<string> $warnings
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $operation,
        public Card $card,
        public ?CardRevision $previousRevision,
        public CardRevision $newRevision,
        public bool $dryRun,
        public array $warnings,
        public array $changedFields,
        public DateTimeImmutable $timestamp,
        public ?TransitionResult $transition = null,
    ) {
    }

    /**
     * @return array{
     *     operation: string,
     *     card: string,
     *     previousRevision: string|null,
     *     newRevision: string,
     *     dryRun: bool,
     *     warnings: list<string>,
     *     changedFields: list<string>,
     *     timestamp: string,
     *     transition: array{previousLane: string, newLane: string, previousRevision: string, newRevision: string, actor: string|null, timestamp: string, warnings: list<string>, changedFields: list<string>}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'operation'        => $this->operation,
            'card'             => $this->card->id->toString(),
            'previousRevision' => $this->previousRevision?->toString(),
            'newRevision'      => $this->newRevision->toString(),
            'dryRun'           => $this->dryRun,
            'warnings'         => $this->warnings,
            'changedFields'    => $this->changedFields,
            'timestamp'        => $this->timestamp->format('Y-m-d\TH:i:sP'),
            'transition'       => $this->transition?->toArray(),
        ];
    }
}
