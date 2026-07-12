<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Repository;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Repository\CardParser;
use voku\AgentKanban\Repository\CardSerializer;
use voku\AgentKanban\Tests\Support\CardFactory;

final class CardSerializerTest extends TestCase
{
    private CardSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new CardSerializer();
    }

    public function testSerializesMinimalCard(): void
    {
        $card = CardFactory::make('ABC-1', title: 'Hello');
        $output = $this->serializer->serialize($card);

        self::assertStringStartsWith("# ABC-1: Hello\n\n- **Ticket:** ABC-1\n- **Lane:** BACKLOG\n", $output);
        self::assertStringEndsWith("\n", $output);
        self::assertStringNotContainsString("\n\n\n", $output);
    }

    public function testOmitsEmptyOptionalFields(): void
    {
        $card = CardFactory::make('ABC-1');
        $output = $this->serializer->serialize($card);

        self::assertStringNotContainsString('Status:', $output);
        self::assertStringNotContainsString('Domain:', $output);
        self::assertStringNotContainsString('Assignee:', $output);
    }

    public function testFieldOrderIsStable(): void
    {
        $card = CardFactory::make(
            'ABC-1',
            status: 'Selected',
            domain: 'Security',
            assignee: 'codex',
            summary: 'S',
            nextAction: 'N',
            validation: 'V',
            priority: 3,
            wave: 'Wave 1',
        );

        $output = $this->serializer->serialize($card);
        $labels = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^-\s*\*\*([^*]+):\*\*/', $line, $matches) === 1) {
                $labels[] = $matches[1];
            }
        }

        self::assertSame(
            ['Ticket', 'Lane', 'Status', 'Domain', 'Assignee', 'Summary', 'Next', 'Validation', 'Priority', 'Wave', 'Format version'],
            $labels,
        );
    }

    public function testCollapsesEmbeddedNewlinesInFieldValues(): void
    {
        $card = CardFactory::make('ABC-1', summary: "line one\nline two");
        $output = $this->serializer->serialize($card);

        self::assertStringContainsString('Summary:** line one line two', $output);
    }

    public function testExtensionFieldsRoundTrip(): void
    {
        $card = CardFactory::make('ABC-1', extensionFields: ['Fit' => 'Recommended']);
        $output = $this->serializer->serialize($card);

        self::assertStringContainsString('- **Fit:** Recommended', $output);

        $reparsed = (new CardParser())->parse($output, 'ABC-1.md');
        self::assertSame(['Fit' => 'Recommended'], $reparsed->extensionFields);
    }

    public function testSectionsOmittedWhenEmpty(): void
    {
        $card = CardFactory::make('ABC-1');
        $output = $this->serializer->serialize($card);

        self::assertStringNotContainsString('## Agent Task Brief', $output);
        self::assertStringNotContainsString('## Handoff / Context', $output);
    }

    public function testRoundTripPreservesEverySemanticField(): void
    {
        $parser = new CardParser();
        $original = $parser->parse(<<<MD
            # ABC-1: Round Trip

            - **Ticket:** ABC-1
            - **Lane:** DOING
            - **Status:** In Progress
            - **Domain:** M365
            - **Assignee:** codex
            - **Updated:** 2026-06-09T11:32:00+00:00
            - **Summary:** Summary text.
            - **Next:** Next action.
            - **Validation:** make test
            - **Priority:** 2
            - **Wave:** Wave 1
            - **Format version:** 1
            - **Fit:** Recommended

            ## Handoff / Context
            Handoff body.

            ## Agent Task Brief
            Brief body.
            MD, 'ABC-1.md');

        $reserialized = $this->serializer->serialize($original);
        $reparsed = $parser->parse($reserialized, 'ABC-1.md');

        self::assertSame($original->title, $reparsed->title);
        self::assertSame($original->lane->toString(), $reparsed->lane->toString());
        self::assertSame($original->status->toString(), $reparsed->status->toString());
        self::assertSame($original->domain, $reparsed->domain);
        self::assertSame($original->assignee, $reparsed->assignee);
        self::assertSame($original->updatedAt?->format('c'), $reparsed->updatedAt?->format('c'));
        self::assertSame($original->summary, $reparsed->summary);
        self::assertSame($original->nextAction, $reparsed->nextAction);
        self::assertSame($original->validation, $reparsed->validation);
        self::assertSame($original->priority, $reparsed->priority);
        self::assertSame($original->wave, $reparsed->wave);
        self::assertSame($original->taskBrief, $reparsed->taskBrief);
        self::assertSame($original->handoffNotes, $reparsed->handoffNotes);
        self::assertSame($original->extensionFields, $reparsed->extensionFields);

        // Serialization is deterministic: serializing twice yields identical bytes.
        self::assertSame($reserialized, $this->serializer->serialize($reparsed));
    }
}
