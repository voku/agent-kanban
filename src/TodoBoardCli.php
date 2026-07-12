<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use voku\AgentKanban\Cli\CliApplication;

/**
 * @deprecated Use Cli\CliApplication or vendor/bin/agent-kanban.
 */
final class TodoBoardCli
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?JiraIssueProvider $jiraIssueProvider = null,
        private readonly ?string $projectPrefix = null,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $mapped = match ($command) {
            'ticket', 'context', 'brief' => ['agent-kanban', 'card', 'show', $argv[2] ?? ''],
            'summary', 'render', 'lane', 'next-pull' => array_merge(['agent-kanban'], array_slice($argv, 1)),
            'help', '--help', '-h', '' => ['agent-kanban', 'help'],
            'jira-sync' => null,
            default => array_merge(['agent-kanban'], array_slice($argv, 1)),
        };

        if ($command === 'jira-sync') {
            if ($this->jiraIssueProvider === null) {
                fwrite(STDERR, "jira-sync requires a JiraIssueProvider implementation from the host project.\n");

                return 1;
            }

            fwrite(STDERR, "jira-sync is deprecated; migrate the host adapter to ExternalIssueProvider and use external-sync.\n");

            return 1;
        }

        if ($mapped === null) {
            return 1;
        }

        $mapped[] = '--root=' . $this->rootPath;
        if ($this->projectPrefix !== null) {
            $configPath = $this->writeCompatibilityConfig();
            $mapped[] = '--config=' . $configPath;
        }

        return (new CliApplication($this->rootPath))->run($mapped);
    }

    private function writeCompatibilityConfig(): string
    {
        $path = sys_get_temp_dir() . '/agent-kanban-compat-' . hash('sha256', $this->rootPath . ':' . $this->projectPrefix) . '.json';
        $content = json_encode(['projectPrefix' => $this->projectPrefix], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Could not create temporary agent-kanban compatibility config.');
        }

        return $path;
    }
}
