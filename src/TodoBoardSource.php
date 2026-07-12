<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use RuntimeException;
use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Rendering\BoardRenderer;
use voku\AgentKanban\Repository\BoardMetadata;

/**
 * @deprecated Use Repository\MarkdownCardRepository and Rendering\BoardRenderer.
 */
final class TodoBoardSource
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $projectPrefix = null,
    ) {
    }

    public function getProjectPrefix(): string
    {
        $metadata = BoardMetadata::fromFile($this->rootPath . '/todo/board.md');
        if ($this->projectPrefix !== null) {
            return $this->projectPrefix;
        }

        if ($metadata->projectPrefix !== null) {
            return $metadata->projectPrefix;
        }

        $context = (new BoardContextFactory())->create($this->rootPath, null, null);

        return $context->config->projectPrefix;
    }

    public function readBoardMarkdown(): string
    {
        $context = (new BoardContextFactory())->create($this->rootPath, null, null);
        $metadata = BoardMetadata::fromFile($this->rootPath . '/todo/board.md');
        $board = new Board(
            $context->config,
            $context->repository->loadAll(),
            $context->repository->resolveCardDirectory() ?? $context->config->cardDirectory,
            $metadata->doneCount,
        );

        return (new BoardRenderer())->renderFull($board);
    }

    public function resolveCardDirectory(): ?string
    {
        $context = (new BoardContextFactory())->create($this->rootPath, null, null);

        return $context->repository->resolveCardDirectory();
    }

    public function readIndexMarkdown(): string
    {
        $path = $this->rootPath . '/TODO.md';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read TODO.md');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}
