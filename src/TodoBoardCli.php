<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\AgentKanbanException;
use voku\AgentKanban\ExternalIssue\ExternalIssueComparator;
use voku\AgentKanban\ExternalIssue\ExternalIssueProvider;
use voku\AgentKanban\ExternalIssue\ExternalIssueRecord;
use voku\AgentKanban\Legacy\LegacyBoardContextResolver;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Rendering\BoardRenderer;

/**
 * @deprecated since 0.2.0. Superseded by
 *             {@see \voku\AgentKanban\Cli\CliApplication}, which
 *             `vendor/bin/agent-kanban` now uses and which delegates to the
 *             typed engine instead of reparsing generated Markdown. This
 *             class is kept only so existing programmatic callers of
 *             `run(array $argv)` keep working; it now delegates internally
 *             to {@see BoardQueryService} / {@see BoardRenderer}. See
 *             UPGRADING.md.
 */
final class TodoBoardCli
{
    private ?string $projectPrefix;

    public function __construct(
        private readonly string $rootPath,
        private readonly ?JiraIssueProvider $jiraIssueProvider = null,
        ?string $projectPrefix = null,
    ) {
        $this->projectPrefix = $projectPrefix;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? '';

            return match ($command) {
                'summary'   => $this->printSummary(),
                'render'    => $this->printRender(),
                'lane'      => $this->printLane($argv[2] ?? ''),
                'next-pull' => $this->printNextPull(),
                'ticket', 'context' => $this->printTicket($argv[2] ?? ''),
                'brief'     => $this->printBrief($argv[2] ?? ''),
                'jira-sync' => $this->printJiraSync(),
                default     => $this->printUsage($command === '' ? 0 : 1),
            };
        } catch (InvalidArgumentException | RuntimeException | AgentKanbanException $exception) {
            fwrite(\STDERR, "ERROR: {$exception->getMessage()}\n");

            return 1;
        }
    }

    private function printSummary(): int
    {
        $board = $this->loadBoard();
        $renderer = new BoardRenderer();

        echo "TODO board summary\n";
        echo "==================\n\n";
        echo $renderer->renderSummary($board) . "\n\n";
        echo $renderer->renderWipHealth($board) . "\n";

        return 0;
    }

    private function printRender(): int
    {
        $board = $this->loadBoard();
        echo (new BoardRenderer())->renderFull($board);

        return 0;
    }

    private function printLane(string $lane): int
    {
        if ($lane === '') {
            fwrite(\STDERR, "Usage: lane <LANE>\n");

            return 1;
        }

        $board = $this->loadBoard();
        echo (new BoardRenderer())->renderLane($board, Lane::fromString($lane)) . "\n";

        return 0;
    }

    private function printNextPull(): int
    {
        $board = $this->loadBoard();
        echo (new BoardRenderer())->renderNextPullCandidates($board) . "\n";

        return 0;
    }

    private function printTicket(string $ticket): int
    {
        if ($ticket === '') {
            fwrite(\STDERR, "Usage: ticket <TICKET>\n");

            return 1;
        }

        $board = $this->loadBoard();
        $card = $board->get($ticket);
        if ($card === null) {
            fwrite(\STDERR, "Ticket not found: {$ticket}\n");

            return 1;
        }

        echo (new BoardRenderer())->renderCard($card) . "\n";

        return 0;
    }

    private function printBrief(string $ticket): int
    {
        if ($ticket === '') {
            fwrite(\STDERR, "Usage: brief <TICKET>\n");

            return 1;
        }

        $board = $this->loadBoard();
        $card = $board->get($ticket);
        if ($card === null || $card->taskBrief === '') {
            fwrite(\STDERR, "No Agent Task Brief found for {$ticket}\n");

            return 1;
        }

        echo $card->taskBrief . "\n";

        return 0;
    }

    private function printJiraSync(): int
    {
        if ($this->jiraIssueProvider === null) {
            throw new RuntimeException('jira-sync requires a JiraIssueProvider implementation from the host project.');
        }

        $board = $this->loadBoard();
        $adapter = $this->adaptJiraProvider($this->jiraIssueProvider);
        $jql = 'project = ' . $adapter->systemName() . ' AND statusCategory != Done ORDER BY updated DESC';
        $drift = (new ExternalIssueComparator())->compare($board->cards, $adapter->fetchActiveIssues($jql), $board->config);

        echo "# TODO/Jira Sync\n\n";
        if ($drift->isEmpty()) {
            echo "_No drift detected between the board and Jira._\n";

            return 0;
        }

        foreach ($drift->entries as $entry) {
            echo sprintf(
                "- [%s] %s%s: %s -> %s\n",
                $entry->kind->value,
                $entry->externalKey,
                $entry->cardId !== null ? ' (' . $entry->cardId . ')' : '',
                $entry->localValue ?? '-',
                $entry->remoteValue ?? '-',
            );
        }

        return 0;
    }

    private function printUsage(int $exitCode): int
    {
        $output = $exitCode === 0 ? \STDOUT : \STDERR;
        fwrite($output, "Usage: agent-kanban <summary|render|lane|next-pull|ticket|context|brief|jira-sync> [args]\n");
        fwrite($output, "This CLI class is deprecated; see voku\\AgentKanban\\Cli\\CliApplication.\n");

        return $exitCode;
    }

    private function loadBoard(): Board
    {
        $context = LegacyBoardContextResolver::resolve($this->rootPath, $this->projectPrefix);
        $cards = $context->repository->loadAll();

        return new Board($context->config, $cards, $context->cardDirectory, $context->metadata->doneCount);
    }

    private function adaptJiraProvider(JiraIssueProvider $provider): ExternalIssueProvider
    {
        return new class ($provider) implements ExternalIssueProvider {
            public function __construct(private readonly JiraIssueProvider $inner)
            {
            }

            public function systemName(): string
            {
                return $this->inner->projectKey();
            }

            public function fetchActiveIssues(string $query): array
            {
                return array_map(
                    static fn (array $issue): ExternalIssueRecord => new ExternalIssueRecord(
                        (string) $issue['key'],
                        (string) $issue['summary'],
                        (string) $issue['status'],
                        null,
                    ),
                    $this->inner->searchIssues($query),
                );
            }
        };
    }
}
