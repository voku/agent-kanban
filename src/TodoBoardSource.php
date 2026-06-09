<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use InvalidArgumentException;
use RuntimeException;

final class TodoBoardSource
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

    private const string TODO_INDEX_FILE = 'TODO.md';

    private const string BOARD_METADATA_FILE = 'todo/board.md';

    private const string JIRA_CARD_DIRECTORY = 'todo/jira';

    private ?string $projectPrefix = null;

    public function __construct(
        private readonly string $rootPath,
        ?string $projectPrefix = null,
    ) {
        $this->projectPrefix = $projectPrefix;
    }

    public function getProjectPrefix(): string
    {
        if ($this->projectPrefix === null) {
            $metadata = $this->readBoardMetadata();
            $this->projectPrefix = $metadata['project_prefix'];
        }

        return $this->projectPrefix;
    }

    public function readBoardMarkdown(): string
    {
        if (!is_dir($this->rootPath . '/' . self::JIRA_CARD_DIRECTORY)) {
            return $this->readLegacyTodo();
        }

        $cards = $this->readCards();
        $metadata = $this->readBoardMetadata();

        return $this->buildBoardMarkdown($cards, $metadata);
    }

    public function readIndexMarkdown(): string
    {
        $path = $this->rootPath . '/' . self::TODO_INDEX_FILE;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read ' . self::TODO_INDEX_FILE);
        }

        return str_replace("\r\n", "\n", $content);
    }

    /**
     * @return list<array{
     *     ticket: string,
     *     title: string,
     *     lane: string,
     *     status: string,
     *     domain: string,
     *     assignee: string,
     *     updated: string,
     *     fit: string,
     *     summary: string,
     *     next: string,
     *     validation: string,
     *     next_pull_rank: int,
     *     wave: string,
     *     brief: string
     * }>
     */
    private function readCards(): array
    {
        $prefix = $this->getProjectPrefix();
        $pattern = $this->rootPath . '/' . self::JIRA_CARD_DIRECTORY . '/' . $prefix . '-*.md';
        $files = glob($pattern);
        if ($files === false) {
            throw new RuntimeException('Could not list TODO card files.');
        }

        sort($files);
        $cards = [];
        foreach ($files as $file) {
            $cards[] = $this->readCard($file);
        }

        return $cards;
    }

    /**
     * @return array{
     *     ticket: string,
     *     title: string,
     *     lane: string,
     *     status: string,
     *     domain: string,
     *     assignee: string,
     *     updated: string,
     *     fit: string,
     *     summary: string,
     *     next: string,
     *     validation: string,
     *     next_pull_rank: int,
     *     wave: string,
     *     brief: string
     * }
     */
    private function readCard(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('Could not read TODO card file: ' . $file);
        }

        $content = str_replace("\r\n", "\n", $content);
        $metadata = $this->parseMetadata($content);
        $ticket = $metadata['Ticket'] ?? $this->ticketFromFilename($file);
        $lane = strtoupper($metadata['Lane'] ?? '');
        if (!in_array($lane, self::LANES, true)) {
            throw new RuntimeException('Invalid lane in ' . $file . ': ' . $lane);
        }

        return [
            'ticket'         => $ticket,
            'title'          => $this->parseTitle($content, $ticket),
            'lane'           => $lane,
            'status'         => $metadata['Status'] ?? '',
            'domain'         => $metadata['Domain'] ?? '',
            'assignee'       => $metadata['Assignee'] ?? '-',
            'updated'        => $metadata['Updated'] ?? '',
            'fit'            => $metadata['Fit'] ?? '',
            'summary'        => $metadata['Summary'] ?? $this->parseTitle($content, $ticket),
            'next'           => $metadata['Next'] ?? '',
            'validation'     => $metadata['Validation'] ?? '',
            'next_pull_rank' => (int)($metadata['Next pull rank'] ?? 0),
            'wave'           => $metadata['Wave'] ?? '',
            'brief'          => $this->extractBrief($content, $ticket),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseMetadata(string $content): array
    {
        $metadata = [];
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, '## ')) {
                break;
            }

            /* INFO: https://regex101.com/?regex=%5E-+%5C%2A%5C%2A%28%5B%5E%2A%5D%2B%29%3A%5C%2A%5C%2A%5Cs%2A%28.%2A%29%24&flavor=pcre */
            if (preg_match('/^- \*\*([^*]+):\*\*\s*(.*)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $metadata[trim($matches[1])] = trim($matches[2]);
        }

        return $metadata;
    }

    private function ticketFromFilename(string $file): string
    {
        $basename = basename($file, '.md');
        $prefix = $this->getProjectPrefix();
        /* INFO: https://regex101.com/?regex=%5E...-%5Cd%2B%24&flavor=pcre */
        if (preg_match('/^' . preg_quote($prefix, '/') . '-\d+$/', $basename) !== 1) {
            throw new RuntimeException('Invalid TODO card filename: ' . $file);
        }

        return $basename;
    }

    private function parseTitle(string $content, string $ticket): string
    {
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^#\s+' . preg_quote($ticket, '/') . ':\s*(.+)$/', trim($line), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return $ticket;
    }

    /**
     * @return array{
     *     done_count: int,
     *     source: string,
     *     project_prefix: string
     * }
     */
    private function readBoardMetadata(): array
    {
        $path = $this->rootPath . '/' . self::BOARD_METADATA_FILE;
        $content = file_get_contents($path);
        $prefix = $this->projectPrefix ?? 'ITPNG';
        if ($content === false) {
            return [
                'done_count' => 0,
                'source'     => 'todo/jira/*.md',
                'project_prefix' => $prefix,
            ];
        }

        $metadata = $this->parseMetadata(str_replace("\r\n", "\n", $content));
        $prefixVal = trim($metadata['Project prefix'] ?? $prefix, " \t\n\r\0\x0B`'\"");
        if ($prefixVal === '') {
            $prefixVal = $prefix;
        }

        return [
            'done_count' => (int)($metadata['Done count'] ?? 0),
            'source'     => $metadata['Source'] ?? 'todo/jira/*.md',
            'project_prefix' => $prefixVal,
        ];
    }

    /**
     * @param list<array{
     *     ticket: string,
     *     title: string,
     *     lane: string,
     *     status: string,
     *     domain: string,
     *     assignee: string,
     *     updated: string,
     *     fit: string,
     *     summary: string,
     *     next: string,
     *     validation: string,
     *     next_pull_rank: int,
     *     wave: string,
     *     brief: string
     * }> $cards
     * @param array{
     *     done_count: int,
     *     source: string,
     *     project_prefix: string
     * } $metadata
     */
    private function buildBoardMarkdown(array $cards, array $metadata): string
    {
        $cardsByLane = $this->cardsByLane($cards);
        $doneCount = $metadata['done_count'];
        $prefix = $this->getProjectPrefix();

        return implode("\n", [
            '# TODO for Coding Agents',
            '',
            '## ' . $prefix . ' Markdown Board',
            '',
            '### ALIGN',
            '',
            '- Problem: keep repository-local TODO work readable by storing active work in topic files.',
            '- Criteria: `todo/jira/*.md` is the source of truth for Jira-derived board cards; `TODO.md` is only the entrypoint.',
            '- Constraints: no raw Jira comments, attachments, full descriptions, secrets, or customer data in tracked repo docs; production stability and privacy first.',
            '',
            '### Source',
            '',
            '- Board source: `' . $metadata['source'] . '`',
            '- TODO entrypoint: `TODO.md`',
            '- Verifier: `make todo_board_verify`',
            '',
            '### Board Policy',
            '',
            '- WIP limit for agent implementation: `3` cards',
            '- Pull rule: pull from `READY` only after current implementation WIP is below the limit.',
            '- Done rule: code change is done only after targeted validation in Docker, Jira outcome sync, a compact `MEMORY.md` entry, and a `make memory_review` pass before pruning the card file from `todo/jira/`.',
            '- Privacy rule: use Jira keys and summaries here; reopen Jira for full request details instead of copying payloads.',
            '- Breaking-change rule: forbidden without explicit user approval and ADR.',
            '',
            '### Kanban Operating Model',
            '',
            '1. Jira remains the source of truth for priority, full descriptions, comments, attachments, stakeholder discussion, and flow metrics.',
            '2. `todo/jira/*.md` is the source of truth for repository-local execution state.',
            '3. `TODO.md` is the short entrypoint only; do not add long task bodies there.',
            '4. Work starts from `READY`; do not pull directly from `BACKLOG` into implementation without first refining the card.',
            '5. Keep implementation WIP at `3` cards across `READY`, `DOING`, and `VERIFY` work selected for an execution wave.',
            '6. Every movement must update exactly one card file and then run `make todo_board_verify`.',
            '',
            '### Lane Rules',
            '',
            '| Lane | Who uses it | Entry condition | Exit condition | Required fields |',
            '| --- | --- | --- | --- | --- |',
            '| READY | Devs and agents | Jira card was reopened, scope is code-adjacent, acceptance criteria are known enough to brief | Implementation starts or card is blocked/deferred | Jira, Status, Domain, Assignee, Updated, Fit, Summary, Agent Task Brief |',
            '| DOING | Devs and agents | Someone is actively implementing or splitting the card | Code is ready for verification or card is blocked | Jira, Status, Domain, Assignee, Updated, Fit, Summary |',
            '| VERIFY | Devs and agents | Implementation exists and needs regression/manual/acceptance evidence | Jira can be updated as done or work returns to DOING | Jira, Status, Domain, Assignee, Updated, Fit, Summary |',
            '| BLOCKED | Devs and leads | External decision, missing acceptance detail, dependency, or security/product question blocks execution | Blocker is resolved and card moves to READY/DOING/BACKLOG | Jira, Status, Domain, Assignee, Updated, Fit, Summary, Blocked Cards prompt |',
            '| BACKLOG | Devs and leads | Card is code-adjacent but not yet refined for execution | Card is selected for a scoped wave and moved to READY | Jira, Status, Domain, Assignee, Updated, Fit, Summary |',
            '',
            '### Card Update Protocol',
            '',
            '1. Reopen the Jira card and verify the tracked summary is still accurate.',
            '2. Edit exactly one file under `todo/jira/`.',
            '3. Keep `Lane`, `Status`, `Fit`, `Next`, and validation fields in that file consistent.',
            '4. If the card is leaving the active board as done, add a compact `MEMORY.md` entry, run `make memory_review`, and then prune the card file.',
            '5. Run `make todo_board_verify`.',
            '',
            '### Agent Pull Checklist',
            '',
            '- [ ] The card is in `READY`.',
            '- [ ] The card has an Agent Task Brief.',
            '- [ ] Jira was reopened for full context.',
            '- [ ] If the card was touched before, the existing Agent Task Brief / repo-local handoff was read before fresh searching.',
            '- [ ] Existing implementation was searched with `rg`.',
            '- [ ] Security/privacy constraints are understood.',
            '- [ ] Validation commands are known before code changes.',
            '- [ ] The intended change fits within a single small execution wave.',
            '',
            $this->buildWipHealthSection($cardsByLane),
            '',
            $this->buildBoardSnapshotSection($cardsByLane, $doneCount),
            '',
            '### Context Model',
            '',
            '- Raw sources: Jira, code, ADRs, runtime observations.',
            '- Compiled context: Agent Task Briefs plus repo-local handoff bullets for touched cards.',
            '- Board index: lane tables, Next Pull Candidates, Blocked Cards, and Backlog Pickup Notes.',
            '- Query rule: before re-screening a touched card, read the board index and existing compiled context first.',
            '- Ingest rule: when a card is screened, narrowed, blocked, or moved to `VERIFY`, refresh the repo-local handoff so the next pass does not repeat the same investigation.',
            '',
            $this->buildDomainMapSection($cards),
            '',
            '### Next Pull Candidates',
            '',
            'These are not an implementation commitment. They are the first cards to refine into concrete agent tasks because Jira already marks them as selected or planned.',
            '',
            $this->buildNextPullTable($cards),
            '',
            '### Suggested Execution Waves',
            '',
            $this->buildWaveTables($cards),
            '',
            '### Kanban Board',
            '',
            $this->buildLaneSections($cardsByLane),
            '',
            '### Agent Task Briefs',
            '',
            $this->buildBriefSections($cards),
            '',
            '### Blocked Cards',
            '',
            $this->buildBlockedCardsTable($cardsByLane['BLOCKED']),
            '',
            '### Backlog Pickup Notes',
            '',
            $this->buildBacklogPickupTable($cardsByLane['BACKLOG']),
            '',
        ]);
    }

    /**
     * @param list<array<string, int|string>> $cards
     *
     * @return array<string, list<array<string, int|string>>>
     */
    private function cardsByLane(array $cards): array
    {
        $cardsByLane = [];
        foreach (self::LANES as $lane) {
            $cardsByLane[$lane] = [];
        }

        foreach ($cards as $card) {
            $cardsByLane[(string)$card['lane']][] = $card;
        }

        return $cardsByLane;
    }

    /**
     * @param array<string, list<array<string, int|string>>> $cardsByLane
     */
    private function buildWipHealthSection(array $cardsByLane): string
    {
        $ready = count($cardsByLane['READY']);
        $doing = count($cardsByLane['DOING']);
        $verify = count($cardsByLane['VERIFY']);
        $blocked = count($cardsByLane['BLOCKED']);
        $backlog = count($cardsByLane['BACKLOG']);

        return implode("\n", [
            '### WIP Health',
            '',
            '| Metric | Value | Signal |',
            '| --- | ---: | --- |',
            '| Active non-done cards | ' . ($ready + $doing + $verify + $blocked + $backlog) . ' | Work visible |',
            '| Selected + planning + progress + test | ' . ($ready + $doing + $verify) . ' | Over proposed agent WIP limit of 3 |',
            '| Blocked / waiting | ' . $blocked . ' | Clarify before coding |',
            '| Backlog candidates | ' . $backlog . ' | Refine before pull |',
        ]);
    }

    /**
     * @param array<string, list<array<string, int|string>>> $cardsByLane
     */
    private function buildBoardSnapshotSection(array $cardsByLane, int $doneCount): string
    {
        return implode("\n", [
            '### Board Snapshot',
            '',
            '| Jira status | Count | Markdown lane |',
            '| --- | ---: | --- |',
            '| Backlog | ' . count($cardsByLane['BACKLOG']) . ' | BACKLOG |',
            '| Selected for Development | ' . $this->countCardsWithStatus($cardsByLane['READY'], 'Selected') . ' | READY |',
            '| In Planung | ' . $this->countCardsWithStatus($cardsByLane['READY'], 'In Planung') . ' | READY |',
            '| in Progress | ' . count($cardsByLane['DOING']) . ' | DOING |',
            '| in Test | ' . count($cardsByLane['VERIFY']) . ' | VERIFY |',
            '| Warten | ' . count($cardsByLane['BLOCKED']) . ' | BLOCKED |',
            '| Fertig | ' . $doneCount . ' | DONE / excluded |',
        ]);
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function countCardsWithStatus(array $cards, string $status): int
    {
        $count = 0;
        foreach ($cards as $card) {
            if ((string)$card['status'] === $status) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildDomainMapSection(array $cards): string
    {
        $counts = [];
        foreach ($cards as $card) {
            $domain = (string)$card['domain'];
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }
        ksort($counts);

        $lines = [
            '### Domain Map',
            '',
            '| Domain | Active cards | Agent note |',
            '| --- | ---: | --- |',
        ];

        foreach ($counts as $domain => $count) {
            $lines[] = '| ' . $this->escapeMarkdownCell($domain) . ' | ' . $count . ' | See card files in `todo/jira/`. |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildNextPullTable(array $cards): string
    {
        $nextPullCards = array_values(array_filter(
            $cards,
            static fn (array $card): bool => (int)$card['next_pull_rank'] > 0
        ));
        usort(
            $nextPullCards,
            static fn (array $a, array $b): int => (int)$a['next_pull_rank'] <=> (int)$b['next_pull_rank']
        );

        if ($nextPullCards === []) {
            return '_No next pull candidates are currently configured._';
        }

        $lines = [
            '| Rank | Jira | Status | Domain | Assignee | Updated | Agent fit | Summary |',
            '| ---: | --- | --- | --- | --- | --- | --- | --- |',
        ];
        foreach ($nextPullCards as $card) {
            $lines[] = '| ' . (int)$card['next_pull_rank'] . ' | ' . $this->cardRow($card) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildWaveTables(array $cards): string
    {
        $cardsByWave = [];
        foreach ($cards as $card) {
            $wave = (string)$card['wave'];
            if ($wave === '') {
                continue;
            }

            $cardsByWave[$wave][] = $card;
        }

        if ($cardsByWave === []) {
            return '_No execution wave rows are currently configured._';
        }

        ksort($cardsByWave);
        $sections = [];
        foreach ($cardsByWave as $wave => $waveCards) {
            $lines = [
                '#### ' . $wave,
                '',
                '| Jira | Status | Domain | Agent action | Validation starter |',
                '| --- | --- | --- | --- | --- |',
            ];
            foreach ($waveCards as $card) {
                $lines[] = '| '
                    . $this->escapeMarkdownCell((string)$card['ticket']) . ' | '
                    . $this->escapeMarkdownCell((string)$card['status']) . ' | '
                    . $this->escapeMarkdownCell((string)$card['domain']) . ' | '
                    . $this->escapeMarkdownCell((string)$card['next']) . ' | '
                    . $this->escapeMarkdownCell((string)$card['validation']) . ' |';
            }

            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param array<string, list<array<string, int|string>>> $cardsByLane
     */
    private function buildLaneSections(array $cardsByLane): string
    {
        $sections = [];
        foreach (self::LANES as $lane) {
            $cards = $cardsByLane[$lane];
            $lines = [
                '#### ' . $lane,
                '',
                '_Count: ' . count($cards) . '_',
                '',
                '| Jira | Status | Domain | Assignee | Updated | Fit | Summary |',
                '| --- | --- | --- | --- | --- | --- | --- |',
            ];

            if ($cards === []) {
                $lines[] = '_No cards._';
            } else {
                foreach ($cards as $card) {
                    $lines[] = '| ' . $this->cardRow($card) . ' |';
                }
            }

            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param array<string, int|string> $card
     */
    private function cardRow(array $card): string
    {
        return implode(' | ', [
            $this->escapeMarkdownCell((string)$card['ticket']),
            $this->escapeMarkdownCell((string)$card['status']),
            $this->escapeMarkdownCell((string)$card['domain']),
            $this->escapeMarkdownCell((string)$card['assignee']),
            $this->escapeMarkdownCell((string)$card['updated']),
            $this->escapeMarkdownCell((string)$card['fit']),
            $this->escapeMarkdownCell((string)$card['summary']),
        ]);
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildBriefSections(array $cards): string
    {
        $briefs = [];
        foreach ($cards as $card) {
            $brief = trim((string)$card['brief']);
            if ($brief === '') {
                continue;
            }

            $briefs[] = $brief;
        }

        return $briefs === [] ? '_No card briefs configured._' : implode("\n\n", $briefs);
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildBlockedCardsTable(array $cards): string
    {
        return $this->buildContextTable(
            $cards,
            ['Jira', 'Status', 'Domain', 'Blocked prompt', 'Summary'],
            static fn (array $card): array => [
                (string)$card['ticket'],
                (string)$card['status'],
                (string)$card['domain'],
                (string)$card['next'],
                (string)$card['summary'],
            ]
        );
    }

    /**
     * @param list<array<string, int|string>> $cards
     */
    private function buildBacklogPickupTable(array $cards): string
    {
        return $this->buildContextTable(
            $cards,
            ['Jira', 'Status', 'Pickup note', 'Summary'],
            static fn (array $card): array => [
                (string)$card['ticket'],
                (string)$card['status'],
                (string)$card['next'],
                (string)$card['summary'],
            ]
        );
    }

    /**
     * @param list<array<string, int|string>>                   $cards
     * @param list<string>                                      $headers
     * @param callable(array<string, int|string>): list<string> $rowBuilder
     */
    private function buildContextTable(array $cards, array $headers, callable $rowBuilder): string
    {
        if ($cards === []) {
            return '_No cards._';
        }

        $lines = [
            '| ' . implode(' | ', array_map($this->escapeMarkdownCell(...), $headers)) . ' |',
            '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |',
        ];

        foreach ($cards as $card) {
            $lines[] = '| ' . implode(' | ', array_map($this->escapeMarkdownCell(...), $rowBuilder($card))) . ' |';
        }

        return implode("\n", $lines);
    }

    private function extractSection(string $content, string $heading): string
    {
        $start = strpos($content, $heading);
        if ($start === false) {
            return '';
        }

        $afterHeading = $start + strlen($heading);
        $next = strpos($content, "\n## ", $afterHeading);
        $end = $next === false ? strlen($content) : $next;

        return trim(substr($content, $start, $end - $start), "\n");
    }

    private function extractBrief(string $content, string $ticket): string
    {
        $explicitBrief = $this->extractSection($content, '## Agent Task Brief');
        if ($explicitBrief !== '') {
            return $explicitBrief;
        }

        if (
            preg_match(
                '/^####\s*' . preg_quote($ticket, '/') . ':.*$/m',
                $content,
                $matches,
                \PREG_OFFSET_CAPTURE
            ) !== 1
        ) {
            return '';
        }

        $matchedOffset = (int)$matches[0][1];
        $nextSection = strpos($content, "\n## ", $matchedOffset + 1);
        $end = $nextSection === false ? strlen($content) : $nextSection;

        return trim(substr($content, $matchedOffset, $end - $matchedOffset), "\n");
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

    private function readLegacyTodo(): string
    {
        $path = $this->rootPath . '/' . self::TODO_INDEX_FILE;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read ' . self::TODO_INDEX_FILE);
        }

        return str_replace("\r\n", "\n", $content);
    }
}
