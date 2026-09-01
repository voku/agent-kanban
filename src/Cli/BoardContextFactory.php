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

    public function create(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
        ?string $boardId = null,
    ): BoardContext {
        $resolved = $this->resolver->resolve($defaultRootPath, $rootOption, $configOption, $boardId);

        return new BoardContext(
            $resolved->rootPath,
            $resolved->config,
            $resolved->repository,
        );
    }

    /**
     * @return array<string, BoardContext>
     */
    public function createAll(
        string $defaultRootPath,
        ?string $rootOption = null,
        ?string $configOption = null,
    ): array {
        $all = $this->resolver->resolveAll($defaultRootPath, $rootOption, $configOption);
        $result = [];
        foreach ($all as $key => $resolved) {
            $result[$key] = new BoardContext(
                $resolved->rootPath,
                $resolved->config,
                $resolved->repository,
            );
        }

        return $result;
    }
}
