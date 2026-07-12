<?php

declare(strict_types=1);

namespace voku\AgentKanban\Legacy;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Repository\BoardMetadata;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Repository\ProjectPrefixInference;

/**
 * @internal Support for the deprecated 0.x facades only.
 */
final class LegacyBoardContextResolver
{
    public static function resolve(string $rootPath, ?string $explicitPrefix): LegacyBoardContext
    {
        $metadata = BoardMetadata::fromFile($rootPath . '/todo/board.md');
        $prefix = $explicitPrefix ?? $metadata->projectPrefix ?? ProjectPrefixInference::infer($rootPath);

        if ($prefix === null) {
            throw new ConfigurationException(
                'Could not determine the project prefix (no explicit prefix, no '
                . 'todo/board.md "Project prefix", and no existing card files to infer it from).',
            );
        }

        $config = BoardConfig::default($prefix);
        $repository = new MarkdownCardRepository($rootPath, $config);

        return new LegacyBoardContext(
            $config,
            $repository,
            $metadata,
            $repository->resolveCardDirectory() ?? $config->legacyCardDirectory,
        );
    }
}
