<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Rendering\BoardRenderer;
use voku\AgentKanban\Rendering\RenderOptions;
use voku\AgentKanban\Tests\Support\CardFactory;

final class BoardRendererTest extends TestCase
{
    public function testRenderFullContainsEveryLaneAndCard(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY', summary: 'Do X'),
        ]), 'todo/cards');

        $output = (new BoardRenderer())->renderFull($board);

        self::assertStringContainsString('#### READY', $output);
        self::assertStringContainsString('ABC-1', $output);
        self::assertStringContainsString('Do X', $output);
        self::assertStringContainsString('## Summary', $output);
        self::assertStringContainsString('## WIP Health', $output);
    }

    public function testRenderLaneShowsCountAndEmptyState(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::empty(), 'todo/cards');
        $output = (new BoardRenderer())->renderLane($board, Lane::fromString('READY'));

        self::assertStringContainsString('_Count: 0_', $output);
        self::assertStringContainsString('_No cards._', $output);
    }

    public function testRenderFilteredAppliesDomainAndSearchFilters(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY', domain: 'Security'),
            CardFactory::make('ABC-2', lane: 'READY', domain: 'M365'),
        ]), 'todo/cards');

        $output = (new BoardRenderer())->renderFiltered($board, new RenderOptions(lanes: ['READY'], domain: 'Security'));

        self::assertStringContainsString('ABC-1', $output);
        self::assertStringNotContainsString('ABC-2', $output);
    }

    public function testRenderFilteredRespectsLimit(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY'),
            CardFactory::make('ABC-2', lane: 'READY'),
        ]), 'todo/cards');

        $output = (new BoardRenderer())->renderFiltered($board, new RenderOptions(lanes: ['READY'], limit: 1));

        self::assertStringContainsString('1 shown / 2 filtered / 2 total', $output);
    }

    public function testEscapesPipeCharactersInTableCells(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY', summary: 'a | b'),
        ]), 'todo/cards');

        $output = (new BoardRenderer())->renderLane($board, Lane::fromString('READY'));

        self::assertStringContainsString('a \\| b', $output);
    }

    public function testRenderCardShowsClaimAndBrief(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'DOING', taskBrief: 'Brief text', handoffNotes: 'Handoff text');
        $output = (new BoardRenderer())->renderCard($card);

        self::assertStringContainsString('Brief text', $output);
        self::assertStringContainsString('Handoff text', $output);
    }
}
