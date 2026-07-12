<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ValidationException;

final class LaneTest extends TestCase
{
    public function testNormalizesCase(): void
    {
        self::assertSame('READY', Lane::fromString('ready')->toString());
        self::assertSame('READY', Lane::fromString('  Ready  ')->toString());
    }

    public function testEquals(): void
    {
        self::assertTrue(Lane::fromString('DOING')->equals(Lane::fromString('doing')));
        self::assertFalse(Lane::fromString('DOING')->equals(Lane::fromString('READY')));
    }

    public function testAllowsUnderscoresAndDigits(): void
    {
        self::assertSame('IN_REVIEW_2', Lane::fromString('in_review_2')->toString());
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);
        Lane::fromString('   ');
    }

    public function testRejectsLeadingDigit(): void
    {
        $this->expectException(ValidationException::class);
        Lane::fromString('1READY');
    }

    public function testRejectsNulByte(): void
    {
        $this->expectException(ValidationException::class);
        Lane::fromString("READY\0");
    }
}
