<?php

declare(strict_types=1);

namespace voku\AgentKanban;

/**
 * @deprecated since 0.2.0, kept for the deprecated {@see TodoBoardCli}. Use
 *             {@see \voku\AgentKanban\Rendering\RenderOptions} instead. See
 *             UPGRADING.md.
 */
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
