<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use DateTimeImmutable;

/**
 * A deliberately small local claim: who is working a card, since when, until
 * when (optional), and which revision was current at claim time.
 *
 * This is not a coordination service. It only records facts read from and
 * written to the card file itself; see
 * {@see \voku\AgentKanban\Mutation\CardMutationService} for the rules that
 * decide whether a new claim may replace this one.
 */
final readonly class Claim
{
    public function __construct(
        public string $actor,
        public DateTimeImmutable $claimedAt,
        public ?DateTimeImmutable $expiresAt,
        public CardRevision $revisionAtClaim,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
