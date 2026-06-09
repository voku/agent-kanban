<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;

final readonly class TodoBoardRenderOptions
{
    /**
     * @param list<string> $lanes
     */
    public function __construct(
        public array $lanes,
        public ?string $domain,
        public ?string $assignee,
        public ?string $status,
        public ?string $search,
        public int $limit,
    ) {
    }
}
