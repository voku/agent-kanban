<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;

final class TodoBoardVerifier
{
    private const TODO_FILE = 'TODO.md';

    /**
     * @var array<string, list<string>>
     */
    private const LANE_STATUS_MAP = [
        'READY'   => ['Selected', 'In Planung'],
        'DOING'   => ['In Progress'],
        'VERIFY'  => ['In Test'],
        'BLOCKED' => ['Warten'],
        'BACKLOG' => ['Backlog'],
    ];

    private ?string $projectPrefix = null;

    public function __construct(
        private readonly string $rootPath,
        ?string $projectPrefix = null,
    ) {
        $this->projectPrefix = $projectPrefix;
    }

    private function getProjectPrefix(): string
    {
        if ($this->projectPrefix === null) {
            $this->projectPrefix = (new TodoBoardSource($this->rootPath))->getProjectPrefix();
        }

        return $this->projectPrefix;
    }

    public function run(): int
    {
        try {
            $index = $this->readIndex();
            $todo = $this->readTodo();
            $this->assertNoSeparateBoardFile();
            $this->assertIndexIsOnlyEntrypoint($index);
            $this->assertRequiredSections($todo);
            $laneTickets = $this->parseLaneTickets($todo);
            $snapshotCounts = $this->parseBoardSnapshotCounts($todo);
            $wipMetrics = $this->parseWipMetrics($todo);

            $this->assertLaneCountsMatchHeadings($todo, $laneTickets);
            $this->assertLaneStatusesAreValid($laneTickets);
            $this->assertTicketsAreUnique($laneTickets);
            $this->assertBoardPolicy($todo);
            $this->assertLaneRules($todo);
            $this->assertAgentPullChecklist($todo);
            $this->assertSnapshotMatchesLanes($snapshotCounts, $laneTickets);
            $this->assertWipMetricsMatchLanes($wipMetrics, $laneTickets);
            $this->assertContextModel($todo);
            $this->assertReadyTicketsHaveBriefs($todo, $laneTickets['READY']);
            $this->assertBlockedTableMatchesLane($todo, $laneTickets['BLOCKED']);
            $this->assertBacklogPickupNotesMatchLane($todo, $laneTickets['BACKLOG']);

            echo "TODO board verification passed.\n";

            return 0;
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, "TODO board verification failed: {$exception->getMessage()}\n");

            return 1;
        }
    }

    private function readTodo(): string
    {
        return (new TodoBoardSource($this->rootPath, $this->getProjectPrefix()))->readBoardMarkdown();
    }

    private function readIndex(): string
    {
        $path = $this->rootPath . '/' . self::TODO_FILE;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read ' . self::TODO_FILE);
        }

        return str_replace("\r\n", "\n", $content);
    }

    private function assertNoSeparateBoardFile(): void
    {
        $prefix = $this->getProjectPrefix();
        $boardFile = $prefix . '_BOARD.md';
        $path = $this->rootPath . '/' . $boardFile;
        if (is_file($path)) {
            throw new RuntimeException($boardFile . ' must not exist; keep the ' . $prefix . ' board source under todo/jira/.');
        }
    }

    private function assertIndexIsOnlyEntrypoint(string $todoIndex): void
    {
        if (!str_contains($todoIndex, 'todo/jira/')) {
            throw new RuntimeException('TODO.md must point agents to todo/jira/ as the board source.');
        }

        if (str_contains($todoIndex, '#### READY')) {
            throw new RuntimeException('TODO.md must stay a compact entrypoint; lane tables belong in todo/jira/*.md.');
        }
    }

    private function assertRequiredSections(string $todo): void
    {
        $prefix = $this->getProjectPrefix();
        $requiredSections = [
            '# TODO for Coding Agents',
            '## ' . $prefix . ' Markdown Board',
            '### WIP Health',
            '### Board Snapshot',
            '### Context Model',
            '### Kanban Operating Model',
            '### Lane Rules',
            '### Card Update Protocol',
            '### Agent Pull Checklist',
            '### Suggested Execution Waves',
            '### Kanban Board',
            '#### READY',
            '#### DOING',
            '#### VERIFY',
            '#### BLOCKED',
            '#### BACKLOG',
            '### Agent Task Briefs',
            '### Blocked Cards',
            '### Backlog Pickup Notes',
        ];

        foreach ($requiredSections as $section) {
            if (!str_contains($todo, $section)) {
                throw new RuntimeException('Missing required section: ' . $section);
            }
        }
    }

    /**
     * @return array<string, list<array{ticket: string, status: string}>>
     */
    private function parseLaneTickets(string $todo): array
    {
        $laneTickets = [];
        foreach (array_keys(self::LANE_STATUS_MAP) as $lane) {
            $section = $this->extractSection($todo, '#### ' . $lane, '#### ');
            $laneTickets[$lane] = $this->parseTicketRows($section);
        }

        return $laneTickets;
    }

    /**
     * @return list<array{ticket: string, status: string}>
     */
    private function parseTicketRows(string $markdown): array
    {
        $rows = [];
        $prefix = $this->getProjectPrefix();
        foreach (explode("\n", $markdown) as $line) {
            /* INFO: https://regex101.com/?regex=%5E%5C%7C%5Cs%2A%28...-%5Cd%2B%29%5Cs%2A%5C%7C&flavor=pcre */
            if (preg_match('/^\|\s*(' . preg_quote($prefix, '/') . '-\d+)\s*\|/', $line) !== 1) {
                continue;
            }

            $cells = $this->splitMarkdownTableRow($line);
            if (count($cells) < 2) {
                throw new RuntimeException('Malformed ticket row: ' . $line);
            }

            $rows[] = [
                'ticket' => $cells[0],
                'status' => $cells[1],
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function splitMarkdownTableRow(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = trim($trimmed, '|');
        $cells = [];
        foreach (explode('|', $trimmed) as $cell) {
            $cells[] = trim(str_replace('\|', '|', $cell));
        }

        return $cells;
    }

    /**
     * @return array<string, int>
     */
    private function parseBoardSnapshotCounts(string $todo): array
    {
        $section = $this->extractSection($todo, '### Board Snapshot', '### ');
        $counts = [];
        foreach (explode("\n", $section) as $line) {
            /* INFO: https://regex101.com/?regex=%5E%5C%7C%5Cs%2A%28%5B%5E%7C%5D%2B%3F%29%5Cs%2A%5C%7C%5Cs%2A%28%5Cd%2B%29%5Cs%2A%5C%7C&flavor=pcre */
            if (preg_match('/^\|\s*([^|]+?)\s*\|\s*(\d+)\s*\|/', $line, $matches) !== 1) {
                continue;
            }

            $counts[trim($matches[1])] = (int)$matches[2];
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function parseWipMetrics(string $todo): array
    {
        $section = $this->extractSection($todo, '### WIP Health', '### ');
        $metrics = [];
        foreach (explode("\n", $section) as $line) {
            /* INFO: https://regex101.com/?regex=%5E%5C%7C%5Cs%2A%28%5B%5E%7C%5D%2B%3F%29%5Cs%2A%5C%7C%5Cs%2A%28%5Cd%2B%29%5Cs%2A%5C%7C&flavor=pcre */
            if (preg_match('/^\|\s*([^|]+?)\s*\|\s*(\d+)\s*\|/', $line, $matches) !== 1) {
                continue;
            }

            $metrics[trim($matches[1])] = (int)$matches[2];
        }

        return $metrics;
    }

    /**
     * @param array<string, list<array{ticket: string, status: string}>> $laneTickets
     */
    private function assertLaneCountsMatchHeadings(string $todo, array $laneTickets): void
    {
        foreach ($laneTickets as $lane => $tickets) {
            $section = $this->extractSection($todo, '#### ' . $lane, '#### ');
            /* INFO: https://regex101.com/?regex=_Count%3A%5Cs%2A%28%5Cd%2B%29_&flavor=pcre */
            if (preg_match('/_Count:\s*(\d+)_/', $section, $matches) !== 1) {
                throw new RuntimeException('Missing _Count_ marker for lane ' . $lane);
            }

            $expected = (int)$matches[1];
            $actual = count($tickets);
            if ($expected !== $actual) {
                throw new RuntimeException(sprintf('Lane %s count mismatch: marker=%d rows=%d', $lane, $expected, $actual));
            }
        }
    }

    /**
     * @param array<string, list<array{ticket: string, status: string}>> $laneTickets
     */
    private function assertLaneStatusesAreValid(array $laneTickets): void
    {
        foreach ($laneTickets as $lane => $tickets) {
            $allowedStatuses = self::LANE_STATUS_MAP[$lane] ?? null;
            if ($allowedStatuses === null) {
                throw new RuntimeException('Unknown board lane: ' . $lane);
            }

            foreach ($tickets as $ticket) {
                if (!in_array($ticket['status'], $allowedStatuses, true)) {
                    throw new RuntimeException(sprintf('%s is in lane %s but has status "%s".', $ticket['ticket'], $lane, $ticket['status']));
                }
            }
        }
    }

    /**
     * @param array<string, list<array{ticket: string, status: string}>> $laneTickets
     */
    private function assertTicketsAreUnique(array $laneTickets): void
    {
        $seen = [];
        foreach ($laneTickets as $lane => $tickets) {
            foreach ($tickets as $ticket) {
                if (isset($seen[$ticket['ticket']])) {
                    throw new RuntimeException(sprintf('%s appears in both %s and %s.', $ticket['ticket'], $seen[$ticket['ticket']], $lane));
                }

                $seen[$ticket['ticket']] = $lane;
            }
        }
    }

    private function assertBoardPolicy(string $todo): void
    {
        $section = $this->extractSection($todo, '### Board Policy', '### ');
        $requiredPolicyLines = [
            '- WIP limit for agent implementation: `3` cards',
            '- Pull rule: pull from `READY` only after current implementation WIP is below the limit.',
            '- Done rule: code change is done only after targeted validation in Docker, Jira outcome sync, a compact `MEMORY.md` entry, and a `make memory_review` pass before pruning the card file from `todo/jira/`.',
            '- Privacy rule: use Jira keys and summaries here; reopen Jira for full request details instead of copying payloads.',
        ];

        foreach ($requiredPolicyLines as $line) {
            if (!str_contains($section, $line)) {
                throw new RuntimeException('Missing board policy line: ' . $line);
            }
        }
    }

    private function assertLaneRules(string $todo): void
    {
        $section = $this->extractSection($todo, '### Lane Rules', '### ');
        foreach (array_keys(self::LANE_STATUS_MAP) as $lane) {
            if (!str_contains($section, '| ' . $lane . ' |')) {
                throw new RuntimeException('Lane Rules table is missing lane: ' . $lane);
            }
        }
    }

    private function assertAgentPullChecklist(string $todo): void
    {
        $section = $this->extractSection($todo, '### Agent Pull Checklist', '### ');
        $requiredChecklistItems = [
            '- [ ] The card is in `READY`.',
            '- [ ] The card has an Agent Task Brief.',
            '- [ ] Jira was reopened for full context.',
            '- [ ] If the card was touched before, the existing Agent Task Brief / repo-local handoff was read before fresh searching.',
            '- [ ] Existing implementation was searched with `rg`.',
            '- [ ] Validation commands are known before code changes.',
        ];

        foreach ($requiredChecklistItems as $item) {
            if (!str_contains($section, $item)) {
                throw new RuntimeException('Agent Pull Checklist is missing item: ' . $item);
            }
        }
    }

    private function assertContextModel(string $todo): void
    {
        $section = $this->extractSection($todo, '### Context Model', '### ');
        $requiredLines = [
            '- Raw sources: Jira, code, ADRs, runtime observations.',
            '- Compiled context: Agent Task Briefs plus repo-local handoff bullets for touched cards.',
            '- Board index: lane tables, Next Pull Candidates, Blocked Cards, and Backlog Pickup Notes.',
            '- Query rule: before re-screening a touched card, read the board index and existing compiled context first.',
            '- Ingest rule: when a card is screened, narrowed, blocked, or moved to `VERIFY`, refresh the repo-local handoff so the next pass does not repeat the same investigation.',
        ];

        foreach ($requiredLines as $line) {
            if (!str_contains($section, $line)) {
                throw new RuntimeException('Missing context model line: ' . $line);
            }
        }
    }

    /**
     * @param array<string, int>                                         $snapshotCounts
     * @param array<string, list<array{ticket: string, status: string}>> $laneTickets
     */
    private function assertSnapshotMatchesLanes(array $snapshotCounts, array $laneTickets): void
    {
        $expectedByStatus = [
            'Backlog'                  => count($laneTickets['BACKLOG']),
            'Selected for Development' => $this->countTicketsWithStatus($laneTickets['READY'], 'Selected'),
            'In Planung'               => $this->countTicketsWithStatus($laneTickets['READY'], 'In Planung'),
            'in Progress'              => count($laneTickets['DOING']),
            'in Test'                  => count($laneTickets['VERIFY']),
            'Warten'                   => count($laneTickets['BLOCKED']),
        ];

        foreach ($expectedByStatus as $status => $expected) {
            $actual = $snapshotCounts[$status] ?? null;
            if ($actual !== $expected) {
                throw new RuntimeException(sprintf('Board snapshot mismatch for %s: snapshot=%s lanes=%d', $status, (string)$actual, $expected));
            }
        }
    }

    /**
     * @param list<array{ticket: string, status: string}> $tickets
     */
    private function countTicketsWithStatus(array $tickets, string $status): int
    {
        $count = 0;
        foreach ($tickets as $ticket) {
            if ($ticket['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, int>                                         $wipMetrics
     * @param array<string, list<array{ticket: string, status: string}>> $laneTickets
     */
    private function assertWipMetricsMatchLanes(array $wipMetrics, array $laneTickets): void
    {
        $ready = count($laneTickets['READY']);
        $doing = count($laneTickets['DOING']);
        $verify = count($laneTickets['VERIFY']);
        $blocked = count($laneTickets['BLOCKED']);
        $backlog = count($laneTickets['BACKLOG']);
        $active = $ready + $doing + $verify + $blocked + $backlog;

        $expected = [
            'Active non-done cards'                 => $active,
            'Selected + planning + progress + test' => $ready + $doing + $verify,
            'Blocked / waiting'                     => $blocked,
            'Backlog candidates'                    => $backlog,
        ];

        foreach ($expected as $metric => $expectedValue) {
            $actual = $wipMetrics[$metric] ?? null;
            if ($actual !== $expectedValue) {
                throw new RuntimeException(sprintf('WIP metric mismatch for "%s": metric=%s lanes=%d', $metric, (string)$actual, $expectedValue));
            }
        }
    }

    /**
     * @param list<array{ticket: string, status: string}> $readyTickets
     */
    private function assertReadyTicketsHaveBriefs(string $todo, array $readyTickets): void
    {
        $section = $this->extractSection($todo, '### Agent Task Briefs', '### ');
        foreach ($readyTickets as $ticket) {
            if (!str_contains($section, '#### ' . $ticket['ticket'] . ':')) {
                throw new RuntimeException('READY ticket is missing an Agent Task Brief: ' . $ticket['ticket']);
            }
        }
    }

    /**
     * @param list<array{ticket: string, status: string}> $blockedTickets
     */
    private function assertBlockedTableMatchesLane(string $todo, array $blockedTickets): void
    {
        $section = $this->extractSection($todo, '### Blocked Cards', '### ');
        $blockedTableTickets = [];
        foreach ($this->parseTicketRows($section) as $ticket) {
            $blockedTableTickets[] = $ticket['ticket'];
        }

        $laneTicketIds = [];
        foreach ($blockedTickets as $ticket) {
            $laneTicketIds[] = $ticket['ticket'];
        }

        sort($blockedTableTickets);
        sort($laneTicketIds);

        if ($blockedTableTickets !== $laneTicketIds) {
            throw new RuntimeException('Blocked Cards table must contain exactly the BLOCKED lane tickets.');
        }
    }

    /**
     * @param list<array{ticket: string, status: string}> $backlogTickets
     */
    private function assertBacklogPickupNotesMatchLane(string $todo, array $backlogTickets): void
    {
        $section = $this->extractSection($todo, '### Backlog Pickup Notes', '### ');
        $pickupNoteTickets = [];
        foreach ($this->parseTicketRows($section) as $ticket) {
            $pickupNoteTickets[] = $ticket['ticket'];
        }

        $laneTicketIds = [];
        foreach ($backlogTickets as $ticket) {
            $laneTicketIds[] = $ticket['ticket'];
        }

        sort($pickupNoteTickets);
        sort($laneTicketIds);

        if ($pickupNoteTickets !== $laneTicketIds) {
            throw new RuntimeException('Backlog Pickup Notes table must contain exactly the BACKLOG lane tickets.');
        }
    }

    private function extractSection(string $markdown, string $heading, string $nextHeadingPrefix): string
    {
        $start = strpos($markdown, $heading);
        if ($start === false) {
            throw new RuntimeException('Could not find heading: ' . $heading);
        }

        $afterHeading = $start + strlen($heading);
        $next = strpos($markdown, "\n" . $nextHeadingPrefix, $afterHeading);
        if ($next === false) {
            return substr($markdown, $afterHeading);
        }

        return substr($markdown, $afterHeading, $next - $afterHeading);
    }
}
