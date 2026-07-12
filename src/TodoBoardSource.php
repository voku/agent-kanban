<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use RuntimeException;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Legacy\LegacyBoardContextResolver;
use voku\AgentKanban\Rendering\BoardRenderer;
use voku\AgentKanban\Repository\MarkdownCardRepository;

/**
 * @deprecated since 0.2.0. This class used to parse card files, render a
 *             large hard-coded Markdown document, and have that document
 *             re-parsed by {@see TodoBoardVerifier} / {@see TodoBoardCli} —
 *             exactly the "Markdown as internal database" anti-pattern the
 *             1.0 architecture removes (see `docs/architecture.md`).
 *
 *             It is kept only so existing callers of `readBoardMarkdown()` /
 *             `resolveCardDirectory()` keep working; internally it now
 *             delegates to {@see MarkdownCardRepository} and
 *             {@see BoardRenderer} and no longer generates or re-parses the
 *             old project-specific board document (Jira-as-source-of-truth
 *             prose, `MEMORY.md`, Docker validation instructions, ...).
 *             Use {@see MarkdownCardRepository}, {@see BoardRenderer}, and
 *             {@see \voku\AgentKanban\Board} directly instead. See
 *             UPGRADING.md.
 */
final class TodoBoardSource
{
    private ?string $projectPrefix;

    public function __construct(
        private readonly string $rootPath,
        ?string $projectPrefix = null,
    ) {
        $this->projectPrefix = $projectPrefix;
    }

    public function getProjectPrefix(): string
    {
        return LegacyBoardContextResolver::resolve($this->rootPath, $this->projectPrefix)->config->projectPrefix;
    }

    /**
     * `todo/cards/` is the preferred local card directory. `todo/jira/` is
     * still supported so existing boards keep working without migration.
     */
    public function resolveCardDirectory(): ?string
    {
        $placeholderConfig = BoardConfig::default($this->projectPrefix ?? 'X');

        return (new MarkdownCardRepository($this->rootPath, $placeholderConfig))->resolveCardDirectory();
    }

    public function readBoardMarkdown(): string
    {
        $context = LegacyBoardContextResolver::resolve($this->rootPath, $this->projectPrefix);
        $cardDirectory = $context->cardDirectory;

        if ($context->repository->resolveCardDirectory() === null) {
            return $this->readLegacyTodo();
        }

        $allCards = $context->repository->loadAll();
        $ownCards = $allCards->filter(
            static fn ($card): bool => $card->id->prefix === $context->config->projectPrefix,
        );

        $board = new Board($context->config, $ownCards, $cardDirectory, $context->metadata->doneCount);

        return implode("\n", [
            '# TODO for Coding Agents',
            '',
            'This project uses a split-file Kanban board. The active board cards are '
            . 'located in the [' . $cardDirectory . '/](' . $cardDirectory . '/) directory.',
            '',
            '## ' . $context->config->projectPrefix . ' Markdown Board',
            '',
            '- Board source: `' . ($context->metadata->source ?? $cardDirectory . '/*.md') . '`',
            '',
            (new BoardRenderer())->renderFull($board),
        ]);
    }

    public function readIndexMarkdown(): string
    {
        return $this->readLegacyTodo();
    }

    private function readLegacyTodo(): string
    {
        $path = $this->rootPath . '/TODO.md';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read TODO.md');
        }

        return str_replace("\r\n", "\n", $content);
    }
}
