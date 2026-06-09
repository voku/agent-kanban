<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;

final readonly class TodoBoardCard
{
    public function __construct(
        public string $lane,
        public string $ticket,
        public string $status,
        public string $domain,
        public string $assignee,
        public string $updated,
        public string $fit,
        public string $summary,
        public string $nextAction,
    ) {
    }
}
