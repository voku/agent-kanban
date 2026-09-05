<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use InvalidArgumentException;

/**
 * Owner-resolved board context plus the configuration evidence that produced it.
 */
final readonly class BoardContextResolution
{
    public function __construct(
        public BoardContext $context,
        public BoardConfigurationMode $configurationMode,
        public ?string $configurationSourcePath,
    ) {
        if ($configurationMode === BoardConfigurationMode::INFERRED && $configurationSourcePath !== null) {
            throw new InvalidArgumentException('Inferred board configuration must not name a source path.');
        }

        if ($configurationMode !== BoardConfigurationMode::INFERRED && ($configurationSourcePath === null || $configurationSourcePath === '')) {
            throw new InvalidArgumentException('File-backed board configuration must name its source path.');
        }
    }
}
