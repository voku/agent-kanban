<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\MarkdownCardRepository;

/**
 * The resolved (root path, config, repository) triple every CLI command
 * needs. Built once per invocation by {@see BoardContextFactory}.
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
