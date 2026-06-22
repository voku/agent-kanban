<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;

final class TodoBoardCli
{
    /**
     * @var list<string>
     */
    private const array LANES = [
        'READY',
        'DOING',
        'VERIFY',
        'BLOCKED',
        'BACKLOG',
    ];

    private const BOARD_ROW_COLUMN_COUNT = 7;

    /**
     * @var array<string, string>
     */
    private const array JIRA_STATUS_TO_LANE = [
        'Selected'                 => 'READY',
        'Selected for Development' => 'READY',
        'In Planung'               => 'READY',
        'In Progress'              => 'DOING',
        'in Progress'              => 'DOING',
        'In Test'                  => 'VERIFY',
        'Warten'                   => 'BLOCKED',
        'Backlog'                  => 'BACKLOG',
    ];

    private ?string $projectPrefix = null;

    public function __construct(
        private readonly string $rootPath,
        private readonly ?JiraIssueProvider $jiraIssueProvider = null,
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

    private function getCardDirectory(): string
    {
        return (new TodoBoardSource($this->rootPath, $this->getProjectPrefix()))->resolveCardDirectory()
            ?? 'todo/jira';
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $command = $this->getArgument($argv, 1);

            return match ($command) {
                'summary'   => $this->printSummary(),
                'render'    => $this->printRender($argv),
                'lane'      => $this->printLane($this->getArgument($argv, 2)),
                'next-pull' => $this->printSection('### Next Pull Candidates', '### '),
                'ticket'    => $this->printTicket($this->getArgument($argv, 2)),
                'context'   => $this->printTicket($this->getArgument($argv, 2)),
                'brief'     => $this->printBrief($this->getArgument($argv, 2)),
                'jira-sync' => $this->printJiraSync(array_slice($argv, 2)),
                default     => $this->printUsage($command === '' ? 0 : 1),
            };
        } catch (InvalidArgumentException | RuntimeException $exception) {
            fwrite(\STDERR, "ERROR: {$exception->getMessage()}\n");

            return 1;
        }
    }

    private function printSummary(): int
    {
        $todo = $this->readTodo();

        echo "TODO board summary\n";
        echo "==================\n";
        echo "\n";
        echo "Lane counts\n";
        echo "-----------\n";

        foreach (self::LANES as $lane) {
            printf("%-8s %d\n", $lane . ':', $this->extractLaneCount($todo, $lane));
        }

        echo "\n";
        echo "WIP health\n";
        echo "----------\n";

        foreach ($this->parseSimpleCountTable($this->extractSection($todo, '### WIP Health', '### ')) as $label => $value) {
            printf("%-34s %d\n", $label . ':', $value);
        }

        echo "\n";
        echo "Board snapshot\n";
        echo "--------------\n";

        foreach ($this->parseSimpleCountTable($this->extractSection($todo, '### Board Snapshot', '### ')) as $label => $value) {
            printf("%-34s %d\n", $label . ':', $value);
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     */
    private function printRender(array $argv): int
    {
        $options = $this->parseRenderOptions(array_slice($argv, 2));
        $todo = $this->readTodo();
        $allCards = $this->parseBoardCards($todo);
        $cardsByLane = [];

        foreach ($allCards as $card) {
            if (!$this->cardMatchesRenderOptions($card, $options)) {
                continue;
            }

            $cardsByLane[$card->lane][] = $card;
        }

        echo "# TODO Kanban Render\n\n";
        echo $this->formatRenderFilters($options) . "\n\n";

        foreach ($options->lanes as $lane) {
            $laneCards = $cardsByLane[$lane] ?? [];
            $totalInLane = $this->countCardsInLane($allCards, $lane);
            $filteredInLane = count($laneCards);
            $visibleCards = $this->limitCards($laneCards, $options->limit);

            printf(
                "## %s (%d shown / %d filtered / %d total)\n\n",
                $lane,
                count($visibleCards),
                $filteredInLane,
                $totalInLane
            );

            if ($visibleCards === []) {
                echo "_No cards match the current filters._\n\n";

                continue;
            }

            echo "| Jira | Status | Domain | Assignee | Updated | Fit | Next | Summary |\n";
            echo "| --- | --- | --- | --- | --- | --- | --- | --- |\n";

            foreach ($visibleCards as $card) {
                echo '| '
                    . $this->escapeMarkdownCell($card->ticket) . ' | '
                    . $this->escapeMarkdownCell($card->status) . ' | '
                    . $this->escapeMarkdownCell($card->domain) . ' | '
                    . $this->escapeMarkdownCell($card->assignee) . ' | '
                    . $this->escapeMarkdownCell($card->updated) . ' | '
                    . $this->escapeMarkdownCell($card->fit) . ' | '
                    . $this->escapeMarkdownCell($card->nextAction) . ' | '
                    . $this->escapeMarkdownCell($card->summary) . " |\n";
            }

            echo "\n";
        }

        $prefix = $this->getProjectPrefix();
        echo "Tip: use `make todo_board_ticket_context TICKET={$prefix}-367` for compiled card context.\n";

        return 0;
    }

    private function printLane(string $lane): int
    {
        $normalizedLane = strtoupper(trim($lane));
        if (!in_array($normalizedLane, self::LANES, true)) {
            fwrite(\STDERR, "Unknown lane: {$lane}\n");
            fwrite(\STDERR, "Allowed values: " . implode(', ', self::LANES) . "\n");

            return 1;
        }

        return $this->printSection('#### ' . $normalizedLane, '#### ');
    }

    private function printTicket(string $ticket): int
    {
        $normalizedTicket = $this->normalizeTicket($ticket);
        if ($normalizedTicket === null) {
            fwrite(\STDERR, "Invalid ticket key: {$ticket}\n");

            return 1;
        }

        $todo = $this->readTodo();
        $found = false;

        echo $normalizedTicket . "\n";
        echo str_repeat('=', strlen($normalizedTicket)) . "\n";

        foreach (self::LANES as $lane) {
            $match = $this->findTicketRowWithHeader(
                $this->extractSection($todo, '#### ' . $lane, '#### '),
                $normalizedTicket
            );

            if ($match === null) {
                continue;
            }

            $found = true;
            echo "\n";
            echo "Lane: {$lane}\n";
            echo $match . "\n";
        }

        $nextPull = $this->findTicketRowWithHeader(
            $this->extractSection($todo, '### Next Pull Candidates', '### '),
            $normalizedTicket
        );
        if ($nextPull !== null) {
            $found = true;
            echo "\n";
            echo "Next Pull Candidates\n";
            echo "--------------------\n";
            echo $nextPull . "\n";
        }

        $waveMatches = $this->findTicketRowsInWaveSection(
            $this->extractSection($todo, '### Suggested Execution Waves', '### '),
            $normalizedTicket
        );
        foreach ($waveMatches as $waveMatch) {
            $found = true;
            echo "\n";
            echo $waveMatch . "\n";
        }

        $blockedMatch = $this->findTicketRowWithHeader(
            $this->extractSection($todo, '### Blocked Cards', '### '),
            $normalizedTicket
        );
        if ($blockedMatch !== null) {
            $found = true;
            echo "\n";
            echo "Blocked Cards\n";
            echo "-------------\n";
            echo $blockedMatch . "\n";
        }

        $backlogPickupNote = $this->findTicketRowWithHeader(
            $this->extractSection($todo, '### Backlog Pickup Notes', '### '),
            $normalizedTicket
        );
        if ($backlogPickupNote !== null) {
            $found = true;
            echo "\n";
            echo "Backlog Pickup Notes\n";
            echo "--------------------\n";
            echo $backlogPickupNote . "\n";
        }

        $brief = $this->findTicketBrief($todo, $normalizedTicket);
        if ($brief !== null) {
            $found = true;
            echo "\n";
            echo "Agent Task Brief\n";
            echo "----------------\n";
            echo $brief . "\n";
        }

        if ($found) {
            return 0;
        }

        $cardDirectory = $this->getCardDirectory();
        fwrite(\STDERR, "Ticket not found in {$cardDirectory}/*.md: {$normalizedTicket}\n");

        return 1;
    }

    private function printBrief(string $ticket): int
    {
        $normalizedTicket = $this->normalizeTicket($ticket);
        if ($normalizedTicket === null) {
            fwrite(\STDERR, "Invalid ticket key: {$ticket}\n");

            return 1;
        }

        $brief = $this->findTicketBrief($this->readTodo(), $normalizedTicket);
        if ($brief === null) {
            fwrite(\STDERR, "No Agent Task Brief found for {$normalizedTicket}\n");

            return 1;
        }

        echo $brief . "\n";

        return 0;
    }

    private function printSection(string $heading, string $nextHeadingPrefix): int
    {
        $todo = $this->readTodo();
        $section = trim($this->extractSection($todo, $heading, $nextHeadingPrefix), "\n");

        echo $heading . "\n\n";
        echo $section . "\n";

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function printJiraSync(array $arguments): int
    {
        $todo = $this->readTodo();
        $boardCards = $this->parseBoardCards($todo);
        $boardCardsByTicket = $this->indexCardsByTicket($boardCards);

        if ($boardCardsByTicket === []) {
            $cardDirectory = $this->getCardDirectory();
            echo "# TODO/Jira Sync\n\n";
            echo "_No active Jira-derived board cards found in {$cardDirectory}/*.md._\n";

            return 0;
        }

        if ($this->jiraIssueProvider === null) {
            throw new RuntimeException('jira-sync requires a JiraIssueProvider implementation from the host project.');
        }

        $projectKey = $this->jiraIssueProvider->projectKey();
        $activeJql = $this->parseJiraSyncJql($arguments, $projectKey);
        $activeIssuesByTicket = $this->fetchJiraIssuesByTicket($this->jiraIssueProvider, $activeJql);

        $boardTicketsNoLongerActive = [];
        $boardTicketsWithStatusDrift = [];

        foreach ($boardCardsByTicket as $ticket => $card) {
            if (!isset($activeIssuesByTicket[$ticket])) {
                $boardTicketsNoLongerActive[] = [
                    $ticket,
                    $card->lane,
                    'Missing from active Jira query',
                    $card->status,
                    $card->summary,
                ];

                continue;
            }

            $jiraIssue = $activeIssuesByTicket[$ticket];
            $jiraStatus = $jiraIssue['status'];
            $jiraSummary = $jiraIssue['summary'];
            $suggestedLane = $this->suggestLaneForJiraStatus($jiraStatus);
            if ($jiraStatus !== $card->status || ($suggestedLane !== null && $suggestedLane !== $card->lane)) {
                $boardTicketsWithStatusDrift[] = [
                    $ticket,
                    $jiraStatus,
                    $card->status,
                    $card->lane,
                    $suggestedLane ?? 'DONE / excluded',
                    $jiraSummary,
                ];
            }
        }

        $activeJiraTicketsMissingFromBoard = [];
        foreach ($activeIssuesByTicket as $ticket => $jiraIssue) {
            if (isset($boardCardsByTicket[$ticket])) {
                continue;
            }

            $activeJiraTicketsMissingFromBoard[] = [
                $ticket,
                $jiraIssue['status'],
                $jiraIssue['updated_at'],
                $jiraIssue['summary'],
            ];
        }

        $cardDirectory = $this->getCardDirectory();

        echo "# TODO/Jira Sync\n\n";
        echo '- Project: `' . $this->escapeMarkdownCell($projectKey) . "`\n";
        echo '- Active Jira JQL: `' . $this->escapeMarkdownCell($activeJql) . "`\n";
        echo '- Board tickets checked: `' . count($boardCardsByTicket) . "`\n";
        echo '- Active Jira tickets returned: `' . count($activeIssuesByTicket) . "`\n";
        echo '- Board tickets no longer active in Jira: `' . count($boardTicketsNoLongerActive) . "`\n";
        echo '- Active Jira tickets missing from ' . $cardDirectory . '/*.md: `' . count($activeJiraTicketsMissingFromBoard) . "`\n";
        echo '- Active tickets with status/lane drift: `' . count($boardTicketsWithStatusDrift) . "`\n\n";

        echo "Use the first section to prune done tickets after copying any durable workflow lesson into `infra/doc/` or `infra/doc/agents/skills/`.\n\n";

        $this->printTableSection(
            '## Remove from ' . $cardDirectory . ' after Jira sync',
            ['Jira', 'Board lane', 'Reason', 'Board status', 'Summary'],
            $boardTicketsNoLongerActive,
            '_No active board tickets currently look done/removed in Jira._'
        );

        $this->printTableSection(
            '## Add or refine in ' . $cardDirectory,
            ['Jira', 'Jira status', 'Updated', 'Summary'],
            $activeJiraTicketsMissingFromBoard,
            '_No active Jira tickets are missing from ' . $cardDirectory . '/*.md._'
        );

        $this->printTableSection(
            '## Fix board status or lane drift',
            ['Jira', 'Jira status', 'Board status', 'Board lane', 'Suggested lane', 'Summary'],
            $boardTicketsWithStatusDrift,
            '_No active Jira tickets currently show a board status/lane drift._'
        );

        return 0;
    }

    private function printUsage(int $exitCode): int
    {
        $script = 'scripts/private/todo_board_cli.php';

        $output = $exitCode === 0 ? \STDOUT : \STDERR;
        fwrite($output, "Usage:\n");
        fwrite($output, "  php {$script} summary\n");
        fwrite($output, "  php {$script} render [--lanes=READY,BACKLOG] [--domain=M365] [--assignee=moellekenl] [--status=Backlog] [--search=session] [--limit=10]\n");
        fwrite($output, "  php {$script} lane READY\n");
        fwrite($output, "  php {$script} next-pull\n");
        $prefix = $this->getProjectPrefix();
        fwrite($output, "  php {$script} ticket {$prefix}-367\n");
        fwrite($output, "  php {$script} context {$prefix}-367\n");
        fwrite($output, "  php {$script} brief {$prefix}-367\n");
        fwrite($output, "  php {$script} jira-sync [--jql='project = {$prefix} AND statusCategory != Done ORDER BY updated DESC']\n");

        return $exitCode;
    }

    /**
     * @param list<string> $argv
     */
    private function getArgument(array $argv, int $position): string
    {
        return $argv[$position] ?? '';
    }

    private function readTodo(): string
    {
        return (new TodoBoardSource($this->rootPath, $this->getProjectPrefix()))->readBoardMarkdown();
    }

    /**
     * @param list<string> $arguments
     */
    private function parseRenderOptions(array $arguments): TodoBoardRenderOptions
    {
        $lanes = self::LANES;
        $domain = null;
        $assignee = null;
        $status = null;
        $search = null;
        $limit = 0;
        $laneFilterWasSet = false;

        foreach ($arguments as $argument) {
            [$name, $value] = $this->splitOption($argument);
            if ($name === null) {
                continue;
            }

            switch ($name) {
                case 'lane':
                case 'lanes':
                    if ($laneFilterWasSet) {
                        throw new InvalidArgumentException('Use only one lane filter: --lane or --lanes.');
                    }

                    $laneFilterWasSet = true;
                    $lanes = $this->parseLaneFilter($value);

                    break;
                case 'domain':
                    $domain = $this->emptyStringToNull($value);

                    break;
                case 'assignee':
                    $assignee = $this->emptyStringToNull($value);

                    break;
                case 'status':
                    $status = $this->emptyStringToNull($value);

                    break;
                case 'search':
                    $search = $this->emptyStringToNull($value);

                    break;
                case 'limit':
                    $limit = $this->parseLimit($value);

                    break;
                default:
                    throw new InvalidArgumentException('Unknown render option: --' . $name);
            }
        }

        return new TodoBoardRenderOptions($lanes, $domain, $assignee, $status, $search, $limit);
    }

    /**
     * @return array{0: null|string, 1: string}
     */
    private function splitOption(string $argument): array
    {
        if (!str_starts_with($argument, '--')) {
            return [null, ''];
        }

        $withoutPrefix = substr($argument, 2);
        $separatorPosition = strpos($withoutPrefix, '=');
        if ($separatorPosition === false) {
            return [$withoutPrefix, ''];
        }

        return [
            substr($withoutPrefix, 0, $separatorPosition),
            substr($withoutPrefix, $separatorPosition + 1),
        ];
    }

    /**
     * @return list<string>
     */
    private function parseLaneFilter(string $value): array
    {
        $lanes = [];
        foreach (explode(',', strtoupper($value)) as $lane) {
            $lane = trim($lane);
            if ($lane === '') {
                continue;
            }

            if (!in_array($lane, self::LANES, true)) {
                throw new InvalidArgumentException('Unknown lane in render filter: ' . $lane);
            }

            $lanes[] = $lane;
        }

        return $lanes === [] ? self::LANES : array_values(array_unique($lanes));
    }

    private function parseLimit(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0;
        }

        /* INFO: https://regex101.com/?regex=%5E%5Cd%2B%24&flavor=pcre */
        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            throw new InvalidArgumentException('LIMIT must be a positive integer or 0.');
        }

        return (int)$trimmed;
    }

    private function emptyStringToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param list<string> $arguments
     */
    private function parseJiraSyncJql(array $arguments, string $projectKey): string
    {
        $jql = null;

        foreach ($arguments as $argument) {
            [$name, $value] = $this->splitOption($argument);
            if ($name === null) {
                continue;
            }

            if ($name !== 'jql') {
                throw new InvalidArgumentException('Unknown jira-sync option: --' . $name);
            }

            $jql = $this->emptyStringToNull($value);
        }

        if ($jql !== null) {
            return $jql;
        }

        return 'project = ' . $projectKey . ' AND statusCategory != Done ORDER BY updated DESC';
    }

    /**
     * @return list<TodoBoardCard>
     */
    private function parseBoardCards(string $todo): array
    {
        $waveActions = $this->parseContextRowsByTicket(
            $this->extractSection($todo, '### Suggested Execution Waves', '### '),
            3
        );
        $blockedPrompts = $this->parseContextRowsByTicket(
            $this->extractSection($todo, '### Blocked Cards', '### '),
            3
        );
        $backlogPickupActions = $this->parseContextRowsByTicket(
            $this->extractSection($todo, '### Backlog Pickup Notes', '### '),
            2
        );
        $cards = [];
        $prefix = $this->getProjectPrefix();

        foreach (self::LANES as $lane) {
            foreach ($this->parseRows($this->extractSection($todo, '#### ' . $lane, '#### ')) as $cells) {
                if (count($cells) < self::BOARD_ROW_COLUMN_COUNT || !str_starts_with($cells[0], $prefix . '-')) {
                    continue;
                }

                $ticket = $cells[0];
                $nextAction = $this->resolveNextAction($lane, $ticket, $waveActions, $blockedPrompts, $backlogPickupActions);

                $cards[] = new TodoBoardCard(
                    $lane,
                    $ticket,
                    $cells[1],
                    $cells[2],
                    $cells[3],
                    $cells[4],
                    $cells[5],
                    $cells[6],
                    $nextAction
                );
            }
        }

        return $cards;
    }

    /**
     * @return array<string, string>
     */
    private function parseContextRowsByTicket(string $section, int $contextColumn): array
    {
        $rowsByTicket = [];
        $prefix = $this->getProjectPrefix();
        foreach ($this->parseRows($section) as $cells) {
            if (count($cells) <= $contextColumn || !str_starts_with($cells[0], $prefix . '-')) {
                continue;
            }

            $rowsByTicket[$cells[0]] = $cells[$contextColumn];
        }

        return $rowsByTicket;
    }

    /**
     * @param array<string, string> $waveActions
     * @param array<string, string> $blockedPrompts
     * @param array<string, string> $backlogPickupActions
     */
    private function resolveNextAction(
        string $lane,
        string $ticket,
        array $waveActions,
        array $blockedPrompts,
        array $backlogPickupActions,
    ): string {
        if (
            in_array($lane, ['READY', 'DOING', 'VERIFY'], true)
            && isset($waveActions[$ticket])
            && trim($waveActions[$ticket]) !== ''
        ) {
            return $waveActions[$ticket];
        }

        if ($lane === 'READY') {
            return 'Read Agent Task Brief and start the scoped implementation wave';
        }

        if ($lane === 'DOING') {
            return 'Finish implementation or split blocker';
        }

        if ($lane === 'VERIFY') {
            return 'Collect regression evidence';
        }

        if ($lane === 'BLOCKED') {
            return $blockedPrompts[$ticket] ?? 'Resolve blocker before coding';
        }

        if ($lane === 'BACKLOG') {
            return $backlogPickupActions[$ticket] ?? 'Refine before moving to READY';
        }

        return '';
    }

    /**
     * @return list<list<string>>
     */
    private function parseRows(string $section): array
    {
        $rows = [];
        foreach (explode("\n", $section) as $line) {
            $trimmedLine = trim($line);
            if (!str_starts_with($trimmedLine, '|') || str_starts_with($trimmedLine, '| ---')) {
                continue;
            }

            $rows[] = $this->splitMarkdownTableRow($trimmedLine);
        }

        return $rows;
    }

    /**
     * @param list<TodoBoardCard> $cards
     *
     * @return array<string, TodoBoardCard>
     */
    private function indexCardsByTicket(array $cards): array
    {
        $cardsByTicket = [];

        foreach ($cards as $card) {
            $cardsByTicket[$card->ticket] = $card;
        }

        return $cardsByTicket;
    }

    private function cardMatchesRenderOptions(TodoBoardCard $card, TodoBoardRenderOptions $options): bool
    {
        if (!in_array($card->lane, $options->lanes, true)) {
            return false;
        }

        if ($options->domain !== null && !$this->containsIgnoringCase($card->domain, $options->domain)) {
            return false;
        }

        if ($options->assignee !== null && !$this->containsIgnoringCase($card->assignee, $options->assignee)) {
            return false;
        }

        if ($options->status !== null && !$this->containsIgnoringCase($card->status, $options->status)) {
            return false;
        }

        if ($options->search === null) {
            return true;
        }

        return $this->containsIgnoringCase($card->ticket, $options->search)
            || $this->containsIgnoringCase($card->status, $options->search)
            || $this->containsIgnoringCase($card->domain, $options->search)
            || $this->containsIgnoringCase($card->assignee, $options->search)
            || $this->containsIgnoringCase($card->updated, $options->search)
            || $this->containsIgnoringCase($card->fit, $options->search)
            || $this->containsIgnoringCase($card->summary, $options->search)
            || $this->containsIgnoringCase($card->nextAction, $options->search);
    }

    private function containsIgnoringCase(string $haystack, string $needle): bool
    {
        return str_contains(strtolower($haystack), strtolower($needle));
    }

    /**
     * @param list<TodoBoardCard> $cards
     */
    private function countCardsInLane(array $cards, string $lane): int
    {
        $count = 0;
        foreach ($cards as $card) {
            if ($card->lane === $lane) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<TodoBoardCard> $cards
     *
     * @return list<TodoBoardCard>
     */
    private function limitCards(array $cards, int $limit): array
    {
        if ($limit <= 0) {
            return $cards;
        }

        return array_slice($cards, 0, $limit);
    }

    private function formatRenderFilters(TodoBoardRenderOptions $options): string
    {
        $filters = [
            'lanes=' . implode(',', $options->lanes),
            'domain=' . ($options->domain ?? '*'),
            'assignee=' . ($options->assignee ?? '*'),
            'status=' . ($options->status ?? '*'),
            'search=' . ($options->search ?? '*'),
            'limit=' . ($options->limit === 0 ? 'all' : (string)$options->limit . '/lane'),
        ];

        return '_Filters: ' . implode('; ', $filters) . '_';
    }

    private function escapeMarkdownCell(string $value): string
    {
        /* INFO: https://regex101.com/?regex=%5Cs%2B&flavor=pcre */
        $normalized = preg_replace('/\s+/', ' ', trim($value));
        if ($normalized === null) {
            $normalized = trim($value);
        }

        return str_replace('|', '\|', $normalized);
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     summary: string,
     *     status: string,
     *     updated_at: string
     * }>
     */
    private function fetchJiraIssuesByTicket(JiraIssueProvider $provider, string $jql): array
    {
        $issuesByTicket = [];

        foreach ($provider->searchIssues($jql) as $issue) {
            $ticket = (string)$issue['key'];

            $issuesByTicket[$ticket] = [
                'key'        => $ticket,
                'summary'    => (string)$issue['summary'],
                'status'     => (string)$issue['status'],
                'updated_at' => (string)$issue['updated_at'],
            ];
        }

        return $issuesByTicket;
    }

    private function suggestLaneForJiraStatus(string $jiraStatus): ?string
    {
        foreach (self::JIRA_STATUS_TO_LANE as $knownStatus => $lane) {
            if (strcasecmp($knownStatus, $jiraStatus) === 0) {
                return $lane;
            }
        }

        return null;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function printTableSection(string $heading, array $headers, array $rows, string $emptyMessage): void
    {
        echo $heading . "\n\n";

        if ($rows === []) {
            echo $emptyMessage . "\n\n";

            return;
        }

        $this->printMarkdownTable($headers, $rows);
        echo "\n";
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function printMarkdownTable(array $headers, array $rows): void
    {
        echo '| ' . implode(' | ', array_map($this->escapeMarkdownCell(...), $headers)) . " |\n";
        echo '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";

        foreach ($rows as $row) {
            echo '| ' . implode(' | ', array_map($this->escapeMarkdownCell(...), $row)) . " |\n";
        }
    }

    private function extractLaneCount(string $todo, string $lane): int
    {
        $section = $this->extractSection($todo, '#### ' . $lane, '#### ');
        /* INFO: https://regex101.com/?regex=_Count%3A%5Cs%2A%28%5Cd%2B%29_&flavor=pcre */
        if (preg_match('/_Count:\s*(\d+)_/', $section, $matches) !== 1) {
            throw new RuntimeException('Missing _Count_ marker for lane ' . $lane);
        }

        return (int)$matches[1];
    }

    /**
     * @return array<string, int>
     */
    private function parseSimpleCountTable(string $section): array
    {
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

    private function normalizeTicket(string $ticket): ?string
    {
        $normalizedTicket = strtoupper(trim($ticket));
        $prefix = $this->getProjectPrefix();

        /* INFO: https://regex101.com/?regex=%5E...-%5Cd%2B%24&flavor=pcre */
        return preg_match('/^' . preg_quote($prefix, '/') . '-\d+$/', $normalizedTicket) === 1 ? $normalizedTicket : null;
    }

    private function findTicketBrief(string $todo, string $ticket): ?string
    {
        $briefsSection = $this->extractSection($todo, '### Agent Task Briefs', '### ');

        if (
            preg_match(
                '/^####\s*' . preg_quote($ticket, '/') . ':.*$/m',
                $briefsSection,
                $matches,
                \PREG_OFFSET_CAPTURE
            ) !== 1
        ) {
            return null;
        }

        $matchedHeading = (string)$matches[0][0];
        $matchedOffset = (int)$matches[0][1];
        $sectionStart = $matchedOffset + strlen($matchedHeading);
        $sectionEnd = strpos($briefsSection, "\n#### ", $sectionStart);
        $briefEnd = is_int($sectionEnd) ? $sectionEnd : strlen($briefsSection);

        return trim(substr($briefsSection, $matchedOffset, $briefEnd - $matchedOffset), "\n");
    }

    private function findTicketRowWithHeader(string $section, string $ticket): ?string
    {
        $lines = explode("\n", trim($section, "\n"));

        foreach ($lines as $index => $line) {
            if (preg_match('/^\|\s*' . preg_quote($ticket, '/') . '\s*\|/', trim($line)) !== 1) {
                continue;
            }

            $tableBlock = $this->findTableBlock($lines, $index);
            $header = $tableBlock['header'];
            $separator = $tableBlock['separator'];
            if ($header === null || $separator === null) {
                return trim($line);
            }

            return $header . "\n" . $separator . "\n" . trim($line);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function findTicketRowsInWaveSection(string $section, string $ticket): array
    {
        $lines = explode("\n", trim($section, "\n"));
        $matches = [];
        $currentWave = '';

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);

            if (str_starts_with($trimmedLine, '#### ')) {
                $currentWave = $trimmedLine;

                continue;
            }

            if (preg_match('/^\|\s*' . preg_quote($ticket, '/') . '\s*\|/', $trimmedLine) !== 1) {
                continue;
            }

            $tableBlock = $this->findTableBlock($lines, $index);
            $header = $tableBlock['header'];
            $separator = $tableBlock['separator'];

            $block = $currentWave !== '' ? $currentWave . "\n" : '';
            if ($header !== null && $separator !== null) {
                $block .= $header . "\n" . $separator . "\n";
            }
            $block .= $trimmedLine;

            $matches[] = $block;
        }

        return $matches;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{header: null|string, separator: null|string}
     */
    private function findTableBlock(array $lines, int $rowIndex): array
    {
        $separator = null;
        $header = null;

        for ($index = $rowIndex - 1; $index >= 0; --$index) {
            $trimmedLine = trim($lines[$index]);

            /* INFO: https://regex101.com/?regex=%5E%5C%7C%5Cs%2A---&flavor=pcre */
            if (preg_match('/^\|\s*---/', $trimmedLine) === 1) {
                $separator = $trimmedLine;
                $headerIndex = $index - 1;
                if ($headerIndex >= 0) {
                    $headerCandidate = trim($lines[$headerIndex]);
                    if (str_starts_with($headerCandidate, '|')) {
                        $header = $headerCandidate;
                    }
                }

                break;
            }

            if (str_starts_with($trimmedLine, '|')) {
                continue;
            }

            if ($trimmedLine !== '') {
                break;
            }
        }

        return [
            'header'    => $header,
            'separator' => $separator,
        ];
    }

    /**
     * @return list<string>
     */
    private function splitMarkdownTableRow(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = trim($trimmed, '|');
        $cells = [];
        $currentCell = '';
        $length = strlen($trimmed);

        for ($index = 0; $index < $length; ++$index) {
            $character = $trimmed[$index];
            if ($character === '|' && ($index === 0 || $trimmed[$index - 1] !== '\\')) {
                $cells[] = trim(str_replace('\|', '|', $currentCell));
                $currentCell = '';

                continue;
            }

            $currentCell .= $character;
        }

        $cells[] = trim(str_replace('\|', '|', $currentCell));

        return $cells;
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
