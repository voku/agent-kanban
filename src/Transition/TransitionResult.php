<?php

declare(strict_types=1);

namespace voku\AgentKanban\Transition;

use DateTimeImmutable;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\Lane;

/**
 * The outcome of a successful lane move, produced by
 * {@see \voku\AgentKanban\Mutation\CardMutationService::move()} after the
 * write has already happened.
 */
final readonly class TransitionResult
{
    /**
     * @param list<string> $warnings
     * @param list<string> $changedFields
     */
    public function __construct(
        public Lane $previousLane,
        public Lane $newLane,
        public CardRevision $previousRevision,
        public CardRevision $newRevision,
        public ?string $actor,
        public DateTimeImmutable $timestamp,
        public array $warnings,
        public array $changedFields,
    ) {
    }

    /**
     * @return array{
     *     previousLane: string,
     *     newLane: string,
     *     previousRevision: string,
     *     newRevision: string,
     *     actor: string|null,
     *     timestamp: string,
     *     warnings: list<string>,
     *     changedFields: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'previousLane'     => $this->previousLane->toString(),
            'newLane'          => $this->newLane->toString(),
            'previousRevision' => $this->previousRevision->toString(),
            'newRevision'      => $this->newRevision->toString(),
            'actor'            => $this->actor,
            'timestamp'        => $this->timestamp->format('Y-m-d\TH:i:sP'),
            'warnings'         => $this->warnings,
            'changedFields'    => $this->changedFields,
        ];
    }
}
