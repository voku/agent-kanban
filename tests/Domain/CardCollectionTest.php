<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Tests\Support\CardFactory;

final class CardCollectionTest extends TestCase
{
    public function testFromArrayAndGet(): void
    {
        $collection = CardCollection::fromArray([
            CardFactory::make('ABC-1'),
            CardFactory::make('ABC-2'),
        ]);

        self::assertCount(2, $collection);
        self::assertSame('ABC-1', $collection->get('abc-1')?->id->toString());
        self::assertTrue($collection->has('ABC-2'));
        self::assertNull($collection->get('ABC-3'));
    }

    public function testFromArrayRejectsDuplicateIds(): void
    {
        $this->expectException(ValidationException::class);
        CardCollection::fromArray([
            CardFactory::make('ABC-1'),
            CardFactory::make('abc-1'),
        ]);
    }

    public function testWithCardAddsOrReplaces(): void
    {
        $collection = CardCollection::fromArray([CardFactory::make('ABC-1', lane: 'BACKLOG')]);
        $replaced = $collection->withCard(CardFactory::make('ABC-1', lane: 'READY'));

        self::assertCount(1, $collection, 'original collection is untouched');
        self::assertSame('BACKLOG', $collection->get('ABC-1')?->lane->toString());
        self::assertSame('READY', $replaced->get('ABC-1')?->lane->toString());

        $withNew = $collection->withCard(CardFactory::make('ABC-2'));
        self::assertCount(2, $withNew);
    }

    public function testWithoutCard(): void
    {
        $collection = CardCollection::fromArray([CardFactory::make('ABC-1'), CardFactory::make('ABC-2')]);
        $reduced = $collection->withoutCard(\voku\AgentKanban\Domain\CardId::fromString('ABC-1'));

        self::assertCount(1, $reduced);
        self::assertFalse($reduced->has('ABC-1'));
    }

    public function testFilter(): void
    {
        $collection = CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY'),
            CardFactory::make('ABC-2', lane: 'DOING'),
        ]);

        $ready = $collection->filter(static fn ($card): bool => $card->lane->toString() === 'READY');

        self::assertCount(1, $ready);
        self::assertSame('ABC-1', $ready->all()[0]->id->toString());
    }

    public function testEmpty(): void
    {
        self::assertCount(0, CardCollection::empty());
    }
}
