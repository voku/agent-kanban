<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Exception\ConfigurationException;

/**
 * Resolves the board root/configuration contract for embedding consumers.
 *
 * Resolution order matches the CLI contract: explicit config, conventional
 * todo/kanban.config.json, board metadata, then existing-card prefix inference.
 */
final readonly class BoardContextResolver
{
    private const string DEFAULT_CONFIG_FILE = 'todo/kanban.config.json';

    public function resolve(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
        ?string $boardId = null,
    ): BoardContext {
        $rootPath = $rootOption === '/' ? '/' : ($rootOption !== null ? rtrim($rootOption, '/') : $defaultRootPath);
        $config = $this->resolveConfig($rootPath, $configOption, $boardId);

        return new BoardContext(
            $rootPath,
            $config,
            new MarkdownCardRepository($rootPath, $config),
        );
    }

    /**
     * @return array<string, BoardContext>
     */
    public function resolveAll(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
    ): array {
        $rootPath = $rootOption === '/' ? '/' : ($rootOption !== null ? rtrim($rootOption, '/') : $defaultRootPath);
        $configs = $this->resolveAllConfigs($rootPath, $configOption);

        $result = [];
        foreach ($configs as $key => $config) {
            $result[$key] = new BoardContext(
                $rootPath,
                $config,
                new MarkdownCardRepository($rootPath, $config),
            );
        }

        return $result;
    }

    /**
     * @return array<string, BoardConfig>
     */
    private function resolveAllConfigs(string $rootPath, ?string $configOption): array
    {
        if ($configOption !== null) {
            return BoardConfig::multiFromJsonFile($configOption)['boards'];
        }

        $conventionalPath = $rootPath . '/' . self::DEFAULT_CONFIG_FILE;
        if (is_file($conventionalPath)) {
            return BoardConfig::multiFromJsonFile($conventionalPath)['boards'];
        }

        $single = $this->resolveConfig($rootPath, null, null);
        $key = $single->id ?? $single->projectPrefix;

        return [$key => $single];
    }

    private function resolveConfig(string $rootPath, ?string $configOption, ?string $boardId = null): BoardConfig
    {
        if ($configOption !== null) {
            return BoardConfig::fromJsonFile($configOption, $boardId);
        }

        $conventionalPath = $rootPath . '/' . self::DEFAULT_CONFIG_FILE;
        if (is_file($conventionalPath)) {
            return BoardConfig::fromJsonFile($conventionalPath, $boardId);
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
