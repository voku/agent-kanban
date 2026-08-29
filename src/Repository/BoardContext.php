<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;

/**
 * The owner-resolved board root, configuration and card repository.
 *
 * Consumers ask agent-kanban for this context instead of reconstructing the
 * conventional config path or project-prefix fallback rules themselves.
 */
final readonly class BoardContext
{
    public function __construct(
        public string $rootPath,
        public BoardConfig $config,
        public MarkdownCardRepository $repository,
    ) {
    }
}
