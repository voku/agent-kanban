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
        return $this->resolveWithProvenance($defaultRootPath, $rootOption, $configOption, $boardId)->context;
    }

    public function resolveWithProvenance(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
        ?string $boardId = null,
    ): BoardContextResolution {
        $resolution = $this->resolveOptionalWithProvenance($defaultRootPath, $rootOption, $configOption, $boardId);
        if ($resolution !== null) {
            return $resolution;
        }

        throw new ConfigurationException(
            'Could not determine the project prefix. Provide --config=<path>, '
            . 'add "- **Project prefix:** X" to todo/board.md or board.md, or add a '
            . 'kanban.config.json with a "projectPrefix" key.',
        );
    }

    public function resolveOptional(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
        ?string $boardId = null,
    ): ?BoardContext {
        return $this->resolveOptionalWithProvenance($defaultRootPath, $rootOption, $configOption, $boardId)?->context;
    }

    public function resolveOptionalWithProvenance(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
        ?string $boardId = null,
    ): ?BoardContextResolution {
        $rootPath = $rootOption === '/' ? '/' : ($rootOption !== null ? rtrim($rootOption, '/') : $defaultRootPath);
        $resolvedConfig = $this->resolveConfigResolutionOrNull($rootPath, $configOption, $boardId);
        if ($resolvedConfig === null) {
            return null;
        }

        $config = $resolvedConfig['config'];

        return new BoardContextResolution(
            new BoardContext(
                $rootPath,
                $config,
                new MarkdownCardRepository($rootPath, $config),
            ),
            $resolvedConfig['mode'],
            $resolvedConfig['source_path'],
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

        $directPath = $rootPath . '/kanban.config.json';
        if (is_file($directPath)) {
            return BoardConfig::multiFromJsonFile($directPath)['boards'];
        }

        $single = $this->resolveConfig($rootPath, null, null);
        $key = $single->id ?? $single->projectPrefix;

        return [$key => $single];
    }

    private function resolveConfig(string $rootPath, ?string $configOption, ?string $boardId = null): BoardConfig
    {
        $config = $this->resolveConfigOrNull($rootPath, $configOption, $boardId);
        if ($config !== null) {
            return $config;
        }

        throw new ConfigurationException(
            'Could not determine the project prefix. Provide --config=<path>, '
            . 'add "- **Project prefix:** X" to todo/board.md or board.md, or add a '
            . 'kanban.config.json with a "projectPrefix" key.',
        );
    }

    private function resolveConfigOrNull(string $rootPath, ?string $configOption, ?string $boardId = null): ?BoardConfig
    {
        return $this->resolveConfigResolutionOrNull($rootPath, $configOption, $boardId)['config'] ?? null;
    }

    /**
     * @return array{config: BoardConfig, mode: BoardConfigurationMode, source_path: string|null}|null
     */
    private function resolveConfigResolutionOrNull(
        string $rootPath,
        ?string $configOption,
        ?string $boardId = null,
    ): ?array {
        if ($configOption !== null) {
            return [
                'config' => BoardConfig::fromJsonFile($configOption, $boardId),
                'mode' => BoardConfigurationMode::JSON,
                'source_path' => $configOption,
            ];
        }

        $conventionalPath = $rootPath . '/' . self::DEFAULT_CONFIG_FILE;
        if (is_file($conventionalPath)) {
            return [
                'config' => BoardConfig::fromJsonFile($conventionalPath, $boardId),
                'mode' => BoardConfigurationMode::JSON,
                'source_path' => $conventionalPath,
            ];
        }

        $directPath = $rootPath . '/kanban.config.json';
        if (is_file($directPath)) {
            return [
                'config' => BoardConfig::fromJsonFile($directPath, $boardId),
                'mode' => BoardConfigurationMode::JSON,
                'source_path' => $directPath,
            ];
        }

        $metadataPaths = [
            $rootPath . '/todo/board.md',
            $rootPath . '/board.md',
        ];
        foreach ($metadataPaths as $metadataPath) {
            if (!is_file($metadataPath)) {
                continue;
            }

            $metadata = BoardMetadata::fromFile($metadataPath);
            if ($metadata->projectPrefix !== null) {
                return [
                    'config' => BoardConfig::default($metadata->projectPrefix),
                    'mode' => BoardConfigurationMode::METADATA,
                    'source_path' => $metadataPath,
                ];
            }
        }

        $inferred = ProjectPrefixInference::infer($rootPath);
        if ($inferred !== null) {
            return [
                'config' => BoardConfig::default($inferred),
                'mode' => BoardConfigurationMode::INFERRED,
                'source_path' => null,
            ];
        }

        return null;
    }
}
