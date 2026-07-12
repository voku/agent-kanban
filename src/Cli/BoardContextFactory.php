<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Repository\BoardMetadata;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Repository\ProjectPrefixInference;

/**
 * Resolves the board a CLI invocation is operating on: `--root`, `--config`
 * (an explicit JSON `BoardConfig` file), a conventional
 * `todo/kanban.config.json`, `todo/board.md`'s `Project prefix`, or — as a
 * last resort — the prefix implied by whatever card files already exist.
 * Never hard-codes a project prefix.
 */
final class BoardContextFactory
{
    private const string DEFAULT_CONFIG_FILE = 'todo/kanban.config.json';

    public function create(string $defaultRootPath, ?string $rootOption, ?string $configOption): BoardContext
    {
        $rootPath = $rootOption !== null ? rtrim($rootOption, '/') : $defaultRootPath;
        $config = $this->resolveConfig($rootPath, $configOption);
        $repository = new MarkdownCardRepository($rootPath, $config);

        return new BoardContext($rootPath, $config, $repository);
    }

    private function resolveConfig(string $rootPath, ?string $configOption): BoardConfig
    {
        if ($configOption !== null) {
            return BoardConfig::fromJsonFile($configOption);
        }

        $conventionalPath = $rootPath . '/' . self::DEFAULT_CONFIG_FILE;
        if (is_file($conventionalPath)) {
            return BoardConfig::fromJsonFile($conventionalPath);
        }

        $metadata = BoardMetadata::fromFile($rootPath . '/todo/board.md');
        if ($metadata->projectPrefix !== null) {
            return BoardConfig::default($metadata->projectPrefix);
        }

        $inferred = ProjectPrefixInference::infer($rootPath);
        if ($inferred !== null) {
            return BoardConfig::default($inferred);
        }

        throw new ConfigurationException(
            'Could not determine the project prefix. Provide --config=<path>, '
            . 'add "- **Project prefix:** X" to todo/board.md, or add a '
            . 'todo/kanban.config.json with a "projectPrefix" key.',
        );
    }
}
