<?php

declare(strict_types=1);

namespace voku\AgentKanban\Rendering;

/**
 * Filters for {@see BoardRenderer::renderFiltered()} / the CLI `render`
 * command. An empty `$lanes` list means "all lanes".
 */
final readonly class RenderOptions
{
    /**
     * @param list<string> $lanes
     */
    public function __construct(
        public array $lanes = [],
        public ?string $domain = null,
        public ?string $assignee = null,
        public ?string $status = null,
        public ?string $search = null,
        public int $limit = 0,
    ) {
    }
}
