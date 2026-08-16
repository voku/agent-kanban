<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Cli\ArgvParser;
use voku\AgentKanban\Exception\ValidationException;

final class ArgvParserTest extends TestCase
{
    public function testRejectsUnknownOption(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['card', 'update', 'ABC-1', '--priorit=5']);
    }

    public function testRejectsDuplicateOption(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--limit=1', '--limit=2']);
    }

    public function testRejectsDuplicateOptionAcrossEqualsAndSpacedForms(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--limit=1', '--limit', '2']);
    }

    public function testRejectsMissingValue(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--limit']);
    }

    public function testRejectsMissingValueBeforeAnotherOption(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--limit', '--compact']);
    }

    public function testAcceptsEqualsAndSpacedValueForms(): void
    {
        $equals = ArgvParser::parse(['card', 'claim', 'ABC-1', '--by=Claude', '--move-to-doing']);
        $spaced = ArgvParser::parse(['card', 'claim', 'ABC-1', '--by', 'Claude', '--move-to-doing']);

        self::assertSame($equals, $spaced);
        self::assertSame('Claude', ArgvParser::stringOption($spaced, 'by'));
        self::assertTrue(ArgvParser::boolOption($spaced, 'move-to-doing'));
    }

    public function testAcceptsNegativeIntegerAsSpacedValue(): void
    {
        $parsed = ArgvParser::parse(['render', '--limit', '-1']);

        self::assertSame(-1, ArgvParser::intOption($parsed, 'limit'));
    }

    public function testRejectsInvalidInteger(): void
    {
        $parsed = ArgvParser::parse(['render', '--limit=banana']);

        $this->expectException(ValidationException::class);
        ArgvParser::intOption($parsed, 'limit');
    }

    public function testAcceptsBooleanFlag(): void
    {
        $parsed = ArgvParser::parse(['card', 'update', 'ABC-1', '--dry-run']);

        self::assertTrue(ArgvParser::boolOption($parsed, 'dry-run'));
    }

    public function testCompactIsABooleanFlag(): void
    {
        $parsed = ArgvParser::parse(['render', '--format=json', '--compact']);

        self::assertTrue(ArgvParser::boolOption($parsed, 'compact'));
    }

    public function testCompactRejectsAValue(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--format=json', '--compact=true']);
    }

    public function testFieldsRequiresAValue(): void
    {
        $this->expectException(ValidationException::class);
        ArgvParser::parse(['render', '--format=json', '--fields']);
    }

    public function testFieldsCarriesItsCommaSeparatedValue(): void
    {
        $parsed = ArgvParser::parse(['render', '--format=json', '--fields=id,lane']);

        self::assertSame('id,lane', ArgvParser::stringOption($parsed, 'fields'));
    }
}
