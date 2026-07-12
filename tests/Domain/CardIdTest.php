<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;

final class CardIdTest extends TestCase
{
    public function testParsesPrefixAndNumber(): void
    {
        $id = CardId::fromString('ITPNG-123');

        self::assertSame('ITPNG', $id->prefix);
        self::assertSame(123, $id->number);
        self::assertSame('ITPNG-123', $id->toString());
        self::assertSame('ITPNG-123', (string) $id);
    }

    public function testNormalizesCaseAndWhitespace(): void
    {
        $id = CardId::fromString('  itpng-5  ');

        self::assertSame('ITPNG-5', $id->toString());
    }

    public function testOfBuildsFromPrefixAndNumber(): void
    {
        self::assertSame('ABC-42', CardId::of('abc', 42)->toString());
    }

    public function testEquals(): void
    {
        self::assertTrue(CardId::fromString('ABC-1')->equals(CardId::fromString('abc-1')));
        self::assertFalse(CardId::fromString('ABC-1')->equals(CardId::fromString('ABC-2')));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'no dash' => ['ABC123'];
        yield 'no number' => ['ABC-'];
        yield 'leading digit prefix' => ['1ABC-1'];
        yield 'zero number' => ['ABC-0'];
        yield 'negative number' => ['ABC--1'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('invalidIdProvider')]
    public function testRejectsInvalidFormat(string $value): void
    {
        $this->expectException(ValidationException::class);
        CardId::fromString($value);
    }

    public function testRejectsNulByte(): void
    {
        $this->expectException(ValidationException::class);
        CardId::fromString("ABC-1\0");
    }

    public function testOfRejectsNonPositiveNumber(): void
    {
        $this->expectException(ValidationException::class);
        CardId::of('ABC', 0);
    }
}
