<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Rendering\CardFieldSelection;
use voku\AgentKanban\Rendering\JsonBoardRenderer;
use voku\AgentKanban\Tests\Support\CardFactory;

final class CardFieldSelectionTest extends TestCase
{
    public function testAvailableFieldsMatchTheFullCardObjectExactly(): void
    {
        // The selection vocabulary is only trustworthy if it stays in step
        // with the card shape it projects; a field added to one and not the
        // other would be silently unselectable (or silently unknown).
        $fullCard = (new JsonBoardRenderer())->cardToArray(CardFactory::make('ABC-1'));

        self::assertSame(array_keys($fullCard), CardFieldSelection::AVAILABLE_FIELDS);
    }

    public function testSelectionProjectsOnlyTheNamedFields(): void
    {
        $selection = CardFieldSelection::fromString('lane,status');
        $projected = $selection->apply((new JsonBoardRenderer())->cardToArray(
            CardFactory::make('ABC-1', lane: 'READY', status: 'Selected', taskBrief: 'a very long brief'),
        ));

        self::assertSame(['id', 'lane', 'status'], array_keys($projected));
        self::assertSame('READY', $projected['lane']);
        self::assertSame('Selected', $projected['status']);
    }

    public function testIdIsAlwaysIncludedEvenWhenNotRequested(): void
    {
        $selection = CardFieldSelection::fromString('summary');

        self::assertTrue($selection->includes('id'));
        self::assertSame(['id', 'summary'], $selection->fields);
    }

    public function testFieldsAreEmittedInCanonicalOrderNotRequestOrder(): void
    {
        // One stable key order means a consumer can parse both the full and
        // the projected object with the same code path.
        $selection = CardFieldSelection::fromString('revision,lane,title');

        self::assertSame(['id', 'title', 'lane', 'revision'], $selection->fields);
    }

    public function testAllSelectionIsANoOpProjection(): void
    {
        $full = (new JsonBoardRenderer())->cardToArray(CardFactory::make('ABC-1', summary: 'S'));

        self::assertSame($full, CardFieldSelection::all()->apply($full));
    }

    public function testUnknownFieldIsRejectedWithTheAvailableList(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown card field "brief" in --fields.');

        CardFieldSelection::fromString('id,brief');
    }

    public function testRepeatedFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Card field "lane" may only appear once');

        CardFieldSelection::fromString('lane,status,lane');
    }

    public function testEmptyFieldNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not contain an empty field name');

        CardFieldSelection::fromString('id,,lane');
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        self::assertSame(['id', 'lane', 'status'], CardFieldSelection::fromString(' lane , status ')->fields);
    }

    public function testFieldNamesAreCaseSensitive(): void
    {
        $this->expectException(ValidationException::class);

        CardFieldSelection::fromString('Lane');
    }
}
