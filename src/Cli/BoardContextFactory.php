<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Repository\BoardContextResolver;

/**
 * CLI adapter over the package-owned board-context resolution boundary.
 *
 * Embedding consumers should use BoardContextResolver directly instead of
 * depending on this CLI namespace.
 */
final readonly class BoardContextFactory
{
    public function __construct(private BoardContextResolver $resolver = new BoardContextResolver())
    {
    }

    public function create(string $defaultRootPath, ?string $rootOption, ?string $configOption): BoardContext
    {
        $resolved = $this->resolver->resolve($defaultRootPath, $rootOption, $configOption);

        return new BoardContext(
            $resolved->rootPath,
            $resolved->config,
            $resolved->repository,
        );
    }
}
