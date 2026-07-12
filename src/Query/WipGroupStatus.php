<?php

declare(strict_types=1);

namespace voku\AgentKanban\Query;

/**
 * The health of one configured WIP-limit group (a single lane, or a
 * comma-joined set of lanes whose cards are summed together).
 */
final readonly class WipGroupStatus
{
    public function __construct(
        public string $group,
        public int $limit,
        public int $count,
    ) {
    }

    public function isOverLimit(): bool
    {
        return $this->count > $this->limit;
    }
}
