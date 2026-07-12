<?php

declare(strict_types=1);

namespace voku\AgentKanban\Query;

use DateTimeImmutable;

/**
 * @phpstan-type LaneCounts array<string, int>
 */
final readonly class BoardSummary
{
    /**
     * @param LaneCounts $laneCounts
     */
    public function __construct(
        public array $laneCounts,
        public int $totalCards,
        public int $doneCount,
        public int $formatVersion,
        public DateTimeImmutable $generatedAt,
    ) {
    }
}
