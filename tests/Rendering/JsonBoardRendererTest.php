<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Rendering\CardFieldSelection;
use voku\AgentKanban\Rendering\JsonBoardRenderer;
use voku\AgentKanban\Tests\Support\CardFactory;
use voku\AgentKanban\Verification\BoardVerifier;

final class JsonBoardRendererTest extends TestCase
{
    public function testSummaryEnvelopeHasSchemaVersion(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::empty(), 'todo/cards');
        $array = (new JsonBoardRenderer())->summaryToArray($board);

        self::assertSame(1, $array['schemaVersion']);
        self::assertSame('board-summary', $array['type']);
        self::assertArrayHasKey('generatedAt', $array);
    }

    public function testCardArrayIncludesEveryField(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'READY', status: 'Selected', domain: 'D', assignee: 'A', summary: 'S');
        $array = (new JsonBoardRenderer())->cardToArray($card);

        self::assertSame('ABC-1', $array['id']);
        self::assertSame('READY', $array['lane']);
        self::assertSame('Selected', $array['status']);
        self::assertSame('D', $array['domain']);
        self::assertSame('A', $array['assignee']);
        self::assertSame('S', $array['summary']);
        self::assertArrayHasKey('revision', $array);
        self::assertArrayHasKey('claim', $array);
        self::assertNull($array['claim']);
    }

    public function testVerificationReportToArrayIncludesViolations(): void
    {
        $board = new Board(BoardConfig::default('ABC'), CardCollection::fromArray([
            CardFactory::make('XYZ-1'),
        ]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);
        $array = (new JsonBoardRenderer())->verificationReportToArray($report);

        self::assertFalse($array['isValid']);
        self::assertNotEmpty($array['violations']);
        self::assertArrayHasKey('code', $array['violations'][0]);
        self::assertArrayHasKey('severity', $array['violations'][0]);
    }

    public function testEncodeProducesValidJsonWithTrailingNewline(): void
    {
        $json = (new JsonBoardRenderer())->encode(['a' => 1]);

        self::assertStringEndsWith("\n", $json);
        self::assertSame(['a' => 1], json_decode($json, true));
    }

    public function testEncodeNeverLeaksExceptionTraces(): void
    {
        // The renderer only ever encodes plain arrays built by this class;
        // it has no code path that serializes a Throwable directly.
        $json = (new JsonBoardRenderer())->encode(['message' => 'plain text']);

        self::assertStringNotContainsString('#0 ', $json);
        self::assertStringNotContainsString('.php:', $json);
    }

    public function testCompactEncodeCarriesTheSameDataWithoutPrettyWhitespace(): void
    {
        $renderer = new JsonBoardRenderer();
        $data = ['a' => 1, 'b' => ['c' => 'd']];

        $pretty = $renderer->encode($data);
        $compact = $renderer->encode($data, true);

        self::assertSame(json_decode($pretty, true), json_decode($compact, true));
        self::assertStringNotContainsString('    ', $compact);
        self::assertStringEndsWith("\n", $compact);
        self::assertLessThan(strlen($pretty), strlen($compact));
    }

    public function testCompactEncodeStillEscapesNothingExtra(): void
    {
        $compact = (new JsonBoardRenderer())->encode(['file' => 'todo/cards/ABC-1.md'], true);

        self::assertStringContainsString('todo/cards/ABC-1.md', $compact);
    }

    public function testCardToArrayWithoutSelectionIsUnchanged(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'READY', summary: 'S');
        $renderer = new JsonBoardRenderer();

        self::assertSame($renderer->cardToArray($card), $renderer->cardToArray($card, null));
    }

    public function testCardToArrayHonoursAFieldSelection(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'READY', summary: 'S', taskBrief: 'long brief');
        $array = (new JsonBoardRenderer())->cardToArray($card, CardFieldSelection::fromString('lane'));

        self::assertSame(['id' => 'ABC-1', 'lane' => 'READY'], $array);
    }

    public function testCardEnvelopeHonoursAFieldSelection(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'READY', taskBrief: 'long brief');
        $envelope = (new JsonBoardRenderer())->cardToEnvelope($card, CardFieldSelection::fromString('lane'));

        self::assertSame('card', $envelope['type']);
        self::assertSame(1, $envelope['schemaVersion']);
        self::assertSame(['id', 'lane'], array_keys($envelope['card']));
    }

    public function testCardListEnvelopeHonoursAFieldSelectionForEveryCard(): void
    {
        $envelope = (new JsonBoardRenderer())->cardsToEnvelope(
            [
                CardFactory::make('ABC-1', lane: 'READY', taskBrief: 'long brief'),
                CardFactory::make('ABC-2', lane: 'DOING', taskBrief: 'another long brief'),
            ],
            CardFieldSelection::fromString('lane'),
        );

        self::assertSame('card-list', $envelope['type']);
        self::assertSame(2, $envelope['count']);
        self::assertSame(['id', 'lane'], array_keys($envelope['cards'][0]));
        self::assertSame(['id', 'lane'], array_keys($envelope['cards'][1]));
    }

    public function testProjectionDropsTheUnboundedFieldsThatDominateOutputSize(): void
    {
        $card = CardFactory::make('ABC-1', lane: 'READY', taskBrief: str_repeat('brief prose. ', 500));
        $renderer = new JsonBoardRenderer();

        $full = $renderer->encode($renderer->cardToEnvelope($card));
        $projected = $renderer->encode(
            $renderer->cardToEnvelope($card, CardFieldSelection::fromString('lane,status')),
            true,
        );

        self::assertStringNotContainsString('brief prose.', $projected);
        self::assertLessThan(strlen($full) / 10, strlen($projected));
    }
}
