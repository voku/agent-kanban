<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\CardParser;

final class CardParserTest extends TestCase
{
    private CardParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CardParser();
    }

    public function testParsesFullCard(): void
    {
        $content = <<<MD
            # ABC-1: Sample Card

            - **Ticket:** ABC-1
            - **Lane:** READY
            - **Status:** Selected
            - **Domain:** M365
            - **Assignee:** codex
            - **Created:** 2026-01-01T00:00:00+00:00
            - **Updated:** 2026-06-09T11:32:00+00:00
            - **Summary:** Do the thing.
            - **Next:** Write tests.
            - **Validation:** make test
            - **Priority:** 1
            - **Wave:** Wave 1
            - **Format version:** 1

            ## Handoff / Context
            Some notes.

            ## Agent Task Brief
            Brief body.
            MD;

        $card = $this->parser->parse($content, 'todo/cards/ABC-1.md');

        self::assertSame('ABC-1', $card->id->toString());
        self::assertSame('Sample Card', $card->title);
        self::assertSame('READY', $card->lane->toString());
        self::assertSame('Selected', $card->status->toString());
        self::assertSame('M365', $card->domain);
        self::assertSame('codex', $card->assignee);
        self::assertSame('2026-06-09T11:32:00+00:00', $card->updatedAt?->format('c'));
        self::assertSame('Do the thing.', $card->summary);
        self::assertSame(1, $card->priority);
        self::assertSame('Some notes.', $card->handoffNotes);
        self::assertSame('Brief body.', $card->taskBrief);
    }

    public function testFallsBackToFilenameWhenTicketFieldMissing(): void
    {
        $content = "# Untitled\n\n- **Lane:** BACKLOG\n";
        $card = $this->parser->parse($content, 'todo/cards/ABC-9.md', 'ABC-9');

        self::assertSame('ABC-9', $card->id->toString());
    }

    public function testThrowsWhenNoTicketAndNoFallback(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->parse("# Untitled\n\n- **Lane:** BACKLOG\n", 'orphan.md');
    }

    public function testThrowsWhenLaneMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->parse("# ABC-1: X\n\n- **Ticket:** ABC-1\n", 'ABC-1.md');
    }

    public function testThrowsOnDuplicateMetadataField(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Lane:** DOING\n";
        $this->expectException(ValidationException::class);
        $this->parser->parse($content, 'ABC-1.md');
    }

    public function testUnknownFieldsBecomeExtensionFields(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Fit:** Recommended\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertSame(['Fit' => 'Recommended'], $card->extensionFields);
    }

    public function testUnparseableTimestampIsPreservedNotFatal(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Updated:** not-a-date\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertNull($card->updatedAt);
        self::assertSame('not-a-date', $card->updatedAtRaw);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function legacyTimestampFormatProvider(): iterable
    {
        yield 'iso date only' => ['2026-06-09', '2026-06-09T00:00:00+00:00'];
        yield 'legacy german with time' => ['09.06.2026 12:00:00', '2026-06-09T12:00:00+00:00'];
        yield 'legacy german date only' => ['09.06.2026', '2026-06-09T00:00:00+00:00'];
        yield 'space separated' => ['2026-06-09 12:00:00', '2026-06-09T12:00:00+00:00'];
    }

    #[DataProvider('legacyTimestampFormatProvider')]
    public function testAcceptsLegacyTimestampFormats(string $raw, string $expectedIso): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Updated:** {$raw}\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertNotNull($card->updatedAt);
        self::assertSame($expectedIso, $card->updatedAt->format('c'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolloverTimestampProvider(): iterable
    {
        yield 'out-of-range day, ISO date' => ['2026-02-30'];
        yield 'out-of-range day and month, legacy german date' => ['32.13.2026'];
    }

    #[DataProvider('rolloverTimestampProvider')]
    public function testRejectsOutOfRangeDatesThatWouldSilentlyRollOver(string $raw): void
    {
        // createFromFormat() happily normalizes "2026-02-30" into "2026-03-02"
        // instead of failing; that would let a malformed legacy timestamp be
        // misread as a valid, different date. It must be treated the same as
        // any other unparseable timestamp: preserved raw, not fatal.
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Updated:** {$raw}\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertNull($card->updatedAt);
        self::assertSame($raw, $card->updatedAtRaw);
    }

    public function testLegacyInlineBriefFallback(): void
    {
        $content = "# ABC-1: Title\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n\n#### ABC-1: Title\nInline brief body.\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertStringContainsString('Inline brief body.', $card->taskBrief);
    }

    public function testUnknownSectionsRoundTripViaExtraSections(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n\n## Notes\nExtra section body.\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertStringContainsString('## Notes', $card->extraSectionsRaw);
        self::assertStringContainsString('Extra section body.', $card->extraSectionsRaw);
    }

    public function testClaimParsing(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** DOING\n"
            . '- **Claim:** codex|claimed=2026-06-09T11:00:00+00:00|expires=-|rev=' . str_repeat('a', 64) . "\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertNotNull($card->claim);
        self::assertSame('codex', $card->claim->actor);
        self::assertNull($card->claim->expiresAt);
    }

    public function testMalformedClaimThrows(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** DOING\n- **Claim:** garbage\n";
        $this->expectException(ValidationException::class);
        $this->parser->parse($content, 'ABC-1.md');
    }

    public function testExternalIssueParsing(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **External issue:** jira:ABC-1\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertNotNull($card->externalIssue);
        self::assertSame('jira', $card->externalIssue->system);
        self::assertSame('ABC-1', $card->externalIssue->key);
    }

    public function testMalformedExternalIssueThrows(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **External issue:** no-colon\n";
        $this->expectException(ValidationException::class);
        $this->parser->parse($content, 'ABC-1.md');
    }

    public function testLegacyNextPullRankIsFallbackForPriority(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Next pull rank:** 2\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertSame(2, $card->priority);
    }

    public function testExplicitPriorityWinsOverLegacyRank(): void
    {
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Priority:** 5\n- **Next pull rank:** 2\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertSame(5, $card->priority);
    }

    public function testNormalizesCrlfNewlines(): void
    {
        $content = "# ABC-1: X\r\n\r\n- **Ticket:** ABC-1\r\n- **Lane:** READY\r\n";
        $card = $this->parser->parse($content, 'ABC-1.md');

        self::assertSame('READY', $card->lane->toString());
    }

    public function testRejectsNulBytes(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->parse("# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n\0", 'ABC-1.md');
    }
}
