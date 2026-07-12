<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use DateTimeImmutable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;

/**
 * A point-in-time, timestamped capture of a Board. Rendering and JSON output
 * are built from a snapshot so that "when was this generated" is always
 * explicit and reproducible, independent of when the underlying files are
 * next read.
 */
final readonly class BoardSnapshot
{
    public function __construct(
        public BoardConfig $config,
        public CardCollection $cards,
        public string $cardDirectory,
        public int $doneCount,
        public DateTimeImmutable $generatedAt,
    ) {
    }
}
