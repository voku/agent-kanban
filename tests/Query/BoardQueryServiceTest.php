<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Query;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Tests\Support\CardFactory;

final class BoardQueryServiceTest extends TestCase
{
    /**
     * @param list<Card> $cards
     */
    private function board(array $cards, ?BoardConfig $config = null): Board
    {
        return new Board($config ?? BoardConfig::default('ABC'), CardCollection::fromArray($cards), 'todo/cards');
    }

    public function testSummaryCountsByLane(): void
    {
        $board = $this->board([
            CardFactory::make('ABC-1', lane: 'READY'),
            CardFactory::make('ABC-2', lane: 'READY'),
            CardFactory::make('ABC-3', lane: 'DOING'),
        ]);

        $summary = (new BoardQueryService($board))->summary();

        self::assertSame(2, $summary->laneCounts['READY']);
        self::assertSame(1, $summary->laneCounts['DOING']);
        self::assertSame(0, $summary->laneCounts['BACKLOG']);
        self::assertSame(3, $summary->totalCards);
    }

    public function testByLaneAndByStatusAndByAssigneeAndByDomain(): void
    {
        $board = $this->board([
            CardFactory::make('ABC-1', lane: 'READY', status: 'Selected', domain: 'Security', assignee: 'codex'),
            CardFactory::make('ABC-2', lane: 'DOING', status: 'In Progress', domain: 'M365', assignee: 'lars'),
        ]);
        $query = new BoardQueryService($board);

        self::assertCount(1, $query->byLane('READY'));
        self::assertCount(1, $query->byStatus('selected'));
        self::assertCount(1, $query->byAssignee('CODEX'));
        self::assertCount(1, $query->byDomain('security'));
    }

    public function testSearchMatchesAcrossFields(): void
    {
        $board = $this->board([
            CardFactory::make('ABC-1', summary: 'Fix the login form'),
            CardFactory::make('ABC-2', summary: 'Unrelated'),
        ]);

        $results = (new BoardQueryService($board))->search('login');

        self::assertCount(1, $results);
        self::assertSame('ABC-1', $results[0]->id->toString());
    }

    public function testNextPullCandidatesOrderedByPriorityAndExcludeZero(): void
    {
        $board = $this->board([
            CardFactory::make('ABC-1', priority: 3),
            CardFactory::make('ABC-2', priority: 1),
            CardFactory::make('ABC-3', priority: 0),
            CardFactory::make('ABC-4', priority: null),
        ]);

        $candidates = (new BoardQueryService($board))->nextPullCandidates();

        self::assertCount(2, $candidates);
        self::assertSame('ABC-2', $candidates[0]->id->toString());
        self::assertSame('ABC-1', $candidates[1]->id->toString());
    }

    public function testBlockedCards(): void
    {
        $board = $this->board([
            CardFactory::make('ABC-1', lane: 'BLOCKED'),
            CardFactory::make('ABC-2', lane: 'READY'),
        ]);

        $blocked = (new BoardQueryService($board))->blockedCards();

        self::assertCount(1, $blocked);
        self::assertSame('ABC-1', $blocked[0]->id->toString());
    }

    public function testBlockedCardsEmptyWhenLaneNotConfigured(): void
    {
        $config = new BoardConfig('ABC', lanes: ['A', 'B'], requiredFieldsByLane: []);
        $board = $this->board([CardFactory::make('ABC-1', lane: 'A')], $config);

        self::assertSame([], (new BoardQueryService($board))->blockedCards());
    }

    public function testWipHealthReportsOverLimit(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: [], wipLimits: ['READY,DOING' => 1]);
        $board = $this->board([
            CardFactory::make('ABC-1', lane: 'READY'),
            CardFactory::make('ABC-2', lane: 'DOING'),
        ], $config);

        $health = (new BoardQueryService($board))->wipHealth();

        self::assertFalse($health->isHealthy());
        self::assertSame(2, $health->groups[0]->count);
        self::assertTrue($health->groups[0]->isOverLimit());
    }

    public function testGetLooksUpById(): void
    {
        $board = $this->board([CardFactory::make('ABC-1')]);

        self::assertNotNull((new BoardQueryService($board))->get('abc-1'));
        self::assertNull((new BoardQueryService($board))->get('ABC-9'));
    }
}
