<?php

declare(strict_types=1);

namespace voku\AgentKanban\Query;

use voku\AgentKanban\Board;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\Lane;

/**
 * Typed, read-only queries over a {@see Board}. Every method here operates
 * directly on parsed {@see Card} objects — never on rendered Markdown (see
 * `docs/architecture.md`).
 */
final readonly class BoardQueryService
{
    public function __construct(
        private Board $board,
    ) {
    }

    public function summary(): BoardSummary
    {
        $laneCounts = [];
        foreach ($this->board->config->lanes as $lane) {
            $laneCounts[$lane] = 0;
        }

        foreach ($this->board->cards->all() as $card) {
            $laneCounts[$card->lane->toString()] = ($laneCounts[$card->lane->toString()] ?? 0) + 1;
        }

        return new BoardSummary(
            $laneCounts,
            $this->board->cards->count(),
            $this->board->doneCount,
            $this->board->config->formatVersion,
            new \DateTimeImmutable(),
        );
    }

    public function get(CardId|string $id): ?Card
    {
        return $this->board->get($id);
    }

    /**
     * @return list<Card>
     */
    public function byLane(Lane|string $lane): array
    {
        $laneValue = $lane instanceof Lane ? $lane : Lane::fromString($lane);

        return $this->board->byLane($laneValue);
    }

    /**
     * @return list<Card>
     */
    public function byStatus(string $status): array
    {
        return $this->filter(
            static fn (Card $card): bool => strcasecmp($card->status->toString(), $status) === 0,
        );
    }

    /**
     * @return list<Card>
     */
    public function byAssignee(string $assignee): array
    {
        return $this->filter(
            static fn (Card $card): bool => $card->assignee !== null && strcasecmp($card->assignee, $assignee) === 0,
        );
    }

    /**
     * @return list<Card>
     */
    public function byDomain(string $domain): array
    {
        return $this->filter(
            static fn (Card $card): bool => $card->domain !== null && strcasecmp($card->domain, $domain) === 0,
        );
    }

    /**
     * @return list<Card>
     */
    public function search(string $term): array
    {
        $needle = mb_strtolower($term);

        return $this->filter(function (Card $card) use ($needle): bool {
            $haystacks = [
                $card->id->toString(),
                $card->title,
                $card->status->toString(),
                $card->domain ?? '',
                $card->assignee ?? '',
                $card->summary,
                $card->nextAction,
                $card->wave,
            ];

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Cards with a configured pull priority greater than zero, ordered
     * ascending (rank 1 first). Cards with no priority, or a priority of
     * zero or less, are not candidates.
     *
     * @return list<Card>
     */
    public function nextPullCandidates(): array
    {
        $candidates = $this->filter(static fn (Card $card): bool => $card->priority !== null && $card->priority > 0);

        usort($candidates, static fn (Card $a, Card $b): int => ($a->priority ?? 0) <=> ($b->priority ?? 0));

        return $candidates;
    }

    /**
     * Cards in the `BLOCKED` lane, by convention (see `docs/configuration.md`
     * — hosts that rename this lane lose this convenience query but keep
     * full access to `byLane()`).
     *
     * @return list<Card>
     */
    public function blockedCards(): array
    {
        if (!in_array('BLOCKED', $this->board->config->lanes, true)) {
            return [];
        }

        return $this->byLane('BLOCKED');
    }

    public function wipHealth(): WipHealth
    {
        $groups = [];
        foreach ($this->board->config->wipLimits as $group => $limit) {
            $lanes = array_map('trim', explode(',', $group));
            $count = 0;
            foreach ($lanes as $laneName) {
                $count += count($this->byLane($laneName));
            }

            $groups[] = new WipGroupStatus($group, $limit, $count);
        }

        return new WipHealth($groups);
    }

    /**
     * @param callable(Card): bool $predicate
     *
     * @return list<Card>
     */
    private function filter(callable $predicate): array
    {
        return array_values(array_filter($this->board->cards->all(), $predicate));
    }
}
