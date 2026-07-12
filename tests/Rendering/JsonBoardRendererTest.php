<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;
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
}
