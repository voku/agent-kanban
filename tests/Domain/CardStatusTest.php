<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Exception\ValidationException;

final class CardStatusTest extends TestCase
{
    public function testPreservesCaseAndTrims(): void
    {
        self::assertSame('In Planung', CardStatus::fromString('  In Planung  ')->toString());
    }

    public function testNoneIsEmpty(): void
    {
        self::assertTrue(CardStatus::none()->isEmpty());
        self::assertTrue(CardStatus::fromString('')->isEmpty());
        self::assertFalse(CardStatus::fromString('Selected')->isEmpty());
    }

    public function testEqualsIsCaseSensitiveButEqualsIgnoreCaseIsNot(): void
    {
        $a = CardStatus::fromString('Selected');
        $b = CardStatus::fromString('selected');

        self::assertFalse($a->equals($b));
        self::assertTrue($a->equalsIgnoreCase($b));
    }

    public function testRejectsControlCharacters(): void
    {
        $this->expectException(ValidationException::class);
        CardStatus::fromString("Selected\x01");
    }

    public function testRejectsNulByte(): void
    {
        $this->expectException(ValidationException::class);
        CardStatus::fromString("Selected\0");
    }

    public function testRejectsTooLong(): void
    {
        $this->expectException(ValidationException::class);
        CardStatus::fromString(str_repeat('a', 257));
    }
}
