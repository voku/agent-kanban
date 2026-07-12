<?php

declare(strict_types=1);

namespace voku\AgentKanban\Rendering;

use voku\AgentKanban\Board;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Query\BoardQueryService;

/**
 * Renders a {@see Board} to Markdown. This is an output *projection*: it is
 * never re-parsed by the engine (see `docs/architecture.md`). Deliberately
 * generic — no project-specific policy prose, no hard-coded status
 * vocabulary. Host projects that want that in front of agents put it in
 * their own `AGENTS.md` / `TODO.md`, not here.
 */
final class BoardRenderer
{
    /**
     * The full board: summary, WIP health, next-pull candidates, every
     * lane's cards, blocked cards, and task briefs.
     */
    public function renderFull(Board $board): string
    {
        $query = new BoardQueryService($board);

        $sections = [
            $this->renderSummary($board),
            $this->renderWipHealth($board),
            $this->renderNextPullCandidates($board),
        ];

        foreach ($board->config->lanes as $lane) {
            $sections[] = $this->renderLane($board, Lane::fromString($lane));
        }

        $sections[] = $this->renderBriefs($query->byLane('READY'));

        return implode("\n\n", $sections) . "\n";
    }

    public function renderSummary(Board $board): string
    {
        $summary = (new BoardQueryService($board))->summary();

        $lines = ['## Summary', '', '| Lane | Count |', '| --- | ---: |'];
        foreach ($summary->laneCounts as $lane => $count) {
            $lines[] = '| ' . $lane . ' | ' . $count . ' |';
        }

        $lines[] = '';
        $lines[] = sprintf(
            '_Total: %d active card(s); %d done/archived. Format version: %d._',
            $summary->totalCards,
            $summary->doneCount,
            $summary->formatVersion,
        );

        return implode("\n", $lines);
    }

    public function renderWipHealth(Board $board): string
    {
        $health = (new BoardQueryService($board))->wipHealth();
        if ($health->groups === []) {
            return "## WIP Health\n\n_No WIP limits configured._";
        }

        $lines = ['## WIP Health', '', '| Group | Count | Limit | Status |', '| --- | ---: | ---: | --- |'];
        foreach ($health->groups as $group) {
            $lines[] = sprintf(
                '| %s | %d | %d | %s |',
                $group->group,
                $group->count,
                $group->limit,
                $group->isOverLimit() ? 'OVER LIMIT' : 'OK',
            );
        }

        return implode("\n", $lines);
    }

    public function renderNextPullCandidates(Board $board): string
    {
        $candidates = (new BoardQueryService($board))->nextPullCandidates();
        if ($candidates === []) {
            return "## Next Pull Candidates\n\n_No next pull candidates are currently configured._";
        }

        $lines = ['## Next Pull Candidates', '', '| Rank | Card | Lane | Status | Summary |', '| ---: | --- | --- | --- | --- |'];
        foreach ($candidates as $card) {
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $card->priority ?? 0,
                $this->escape($card->id->toString()),
                $this->escape($card->lane->toString()),
                $this->escape($card->status->toString()),
                $this->escape($card->summary),
            );
        }

        return implode("\n", $lines);
    }

    public function renderLane(Board $board, Lane $lane): string
    {
        $cards = (new BoardQueryService($board))->byLane($lane);

        $lines = [
            '#### ' . $lane->toString(),
            '',
            '_Count: ' . count($cards) . '_',
            '',
            '| Card | Status | Domain | Assignee | Updated | Summary |',
            '| --- | --- | --- | --- | --- | --- |',
        ];

        if ($cards === []) {
            $lines[] = '_No cards._';
        } else {
            foreach ($cards as $card) {
                $lines[] = $this->cardRow($card);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Card> $cards
     */
    public function renderBriefs(array $cards): string
    {
        $briefs = [];
        foreach ($cards as $card) {
            if ($card->taskBrief === '') {
                continue;
            }

            $briefs[] = '#### ' . $card->id->toString() . ': ' . $card->title . "\n" . $card->taskBrief;
        }

        if ($briefs === []) {
            return "## Agent Task Briefs\n\n_No card briefs configured._";
        }

        return "## Agent Task Briefs\n\n" . implode("\n\n", $briefs);
    }

    public function renderCard(Card $card): string
    {
        $lines = [
            $card->id->toString() . ': ' . $card->title,
            str_repeat('=', strlen($card->id->toString() . ': ' . $card->title)),
            '',
            '- Lane: ' . $card->lane->toString(),
            '- Status: ' . $card->status->toString(),
            '- Domain: ' . ($card->domain ?? '-'),
            '- Assignee: ' . ($card->assignee ?? '-'),
            '- Updated: ' . $card->updatedAtRaw,
            '- Summary: ' . $card->summary,
            '- Next: ' . $card->nextAction,
            '- Revision: ' . $card->revision->toString(),
        ];

        if ($card->claim !== null) {
            $lines[] = '- Claimed by: ' . $card->claim->actor . ' (since ' . $card->claim->claimedAt->format('Y-m-d\TH:i:sP') . ')';
        }

        if ($card->taskBrief !== '') {
            $lines[] = '';
            $lines[] = 'Agent Task Brief';
            $lines[] = '----------------';
            $lines[] = $card->taskBrief;
        }

        if ($card->handoffNotes !== '') {
            $lines[] = '';
            $lines[] = 'Handoff / Context';
            $lines[] = '-----------------';
            $lines[] = $card->handoffNotes;
        }

        return implode("\n", $lines);
    }

    public function renderFiltered(Board $board, RenderOptions $options): string
    {
        $query = new BoardQueryService($board);
        $lanes = $options->lanes === [] ? $board->config->lanes : $options->lanes;

        $sections = ['# Filtered Board Render', '', $this->formatFilters($options)];

        foreach ($lanes as $lane) {
            $cards = $this->filterCards($query->byLane($lane), $options);
            $totalInLane = count($query->byLane($lane));
            $visible = $options->limit > 0 ? array_slice($cards, 0, $options->limit) : $cards;

            $sections[] = sprintf('## %s (%d shown / %d filtered / %d total)', $lane, count($visible), count($cards), $totalInLane);

            if ($visible === []) {
                $sections[] = '_No cards match the current filters._';

                continue;
            }

            $lines = ['| Card | Status | Domain | Assignee | Updated | Summary |', '| --- | --- | --- | --- | --- | --- |'];
            foreach ($visible as $card) {
                $lines[] = $this->cardRow($card);
            }

            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * Applies domain/assignee/status/search filtering (everything in
     * {@see RenderOptions} except the lane selection and per-lane limit,
     * which the caller applies itself). Shared by {@see self::renderFiltered()}
     * and the CLI's JSON render path, so both formats see identical results.
     *
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    public function filterCards(array $cards, RenderOptions $options): array
    {
        return array_values(array_filter($cards, function (Card $card) use ($options): bool {
            if ($options->domain !== null && !$this->containsIgnoreCase($card->domain ?? '', $options->domain)) {
                return false;
            }

            if ($options->assignee !== null && !$this->containsIgnoreCase($card->assignee ?? '', $options->assignee)) {
                return false;
            }

            if ($options->status !== null && !$this->containsIgnoreCase($card->status->toString(), $options->status)) {
                return false;
            }

            if ($options->search === null) {
                return true;
            }

            foreach ([$card->id->toString(), $card->status->toString(), $card->domain ?? '', $card->assignee ?? '', $card->summary] as $haystack) {
                if ($this->containsIgnoreCase($haystack, $options->search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function containsIgnoreCase(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    private function formatFilters(RenderOptions $options): string
    {
        $filters = [
            'lanes=' . ($options->lanes === [] ? 'all' : implode(',', $options->lanes)),
            'domain=' . ($options->domain ?? '*'),
            'assignee=' . ($options->assignee ?? '*'),
            'status=' . ($options->status ?? '*'),
            'search=' . ($options->search ?? '*'),
            'limit=' . ($options->limit === 0 ? 'all' : (string) $options->limit . '/lane'),
        ];

        return '_Filters: ' . implode('; ', $filters) . '_';
    }

    private function cardRow(Card $card): string
    {
        return '| ' . implode(' | ', [
            $this->escape($card->id->toString()),
            $this->escape($card->status->toString()),
            $this->escape($card->domain ?? '-'),
            $this->escape($card->assignee ?? '-'),
            $this->escape($card->updatedAtRaw !== '' ? $card->updatedAtRaw : '-'),
            $this->escape($card->summary),
        ]) . ' |';
    }

    private function escape(string $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value));

        return str_replace('|', '\|', $normalized ?? trim($value));
    }
}
