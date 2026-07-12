<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Exception\ValidationException;

final class CardRevisionTest extends TestCase
{
    public function testFromContentIsDeterministic(): void
    {
        $a = CardRevision::fromContent('hello');
        $b = CardRevision::fromContent('hello');

        self::assertTrue($a->equals($b));
        self::assertSame($a->toString(), $b->toString());
        self::assertSame(64, strlen($a->toString()));
    }

    public function testDifferentContentProducesDifferentRevision(): void
    {
        $a = CardRevision::fromContent('hello');
        $b = CardRevision::fromContent('hello!');

        self::assertFalse($a->equals($b));
    }

    public function testFromHexRoundTrips(): void
    {
        $revision = CardRevision::fromContent('some content');
        $roundTripped = CardRevision::fromHex($revision->toString());

        self::assertTrue($revision->equals($roundTripped));
    }

    public function testFromHexRejectsWrongLength(): void
    {
        $this->expectException(ValidationException::class);
        CardRevision::fromHex('abc123');
    }

    public function testFromHexRejectsNonHexCharacters(): void
    {
        $this->expectException(ValidationException::class);
        CardRevision::fromHex(str_repeat('g', 64));
    }
}
