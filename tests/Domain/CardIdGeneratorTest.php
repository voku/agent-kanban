<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardIdGenerator;
use voku\AgentKanban\Exception\ValidationException;

final class CardIdGeneratorTest extends TestCase
{
    public function testGeneratesPositiveSixtyThreeBitNumberUnderThePrefix(): void
    {
        $id = (new CardIdGenerator())->generate('TEST', static fn (CardId $candidate): bool => false);

        self::assertSame('TEST', $id->prefix);
        self::assertGreaterThanOrEqual(1, $id->number);
        self::assertLessThanOrEqual(PHP_INT_MAX, $id->number);
        self::assertTrue(CardId::fromString($id->toString())->equals($id), 'The generated ID must round-trip through the parser.');
    }

    public function testDoesNotDependOnTheExistingBoardNumbers(): void
    {
        $seen = [];
        $generator = new CardIdGenerator(new Randomizer(new Mt19937(1234)));
        // A board whose highest number is 2; a max+1 scheme would answer 3 every time.
        $exists = static fn (CardId $candidate): bool => $candidate->number <= 2;

        for ($i = 0; $i < 50; ++$i) {
            $seen[$generator->generate('TEST', $exists)->toString()] = true;
        }

        self::assertCount(50, $seen);
        self::assertArrayNotHasKey('TEST-3', $seen);
    }

    public function testRetriesWhenTheCandidateAlreadyExistsAndNeverReturnsAnExistingId(): void
    {
        $first = CardId::of('TEST', (new Randomizer(new Mt19937(7)))->getInt(1, PHP_INT_MAX));
        $generator = new CardIdGenerator(new Randomizer(new Mt19937(7)));

        $id = $generator->generate('TEST', static fn (CardId $candidate): bool => $candidate->equals($first));

        self::assertFalse($id->equals($first));
    }

    public function testFailsClosedWhenEveryAttemptCollides(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Could not generate an unused card ID');

        (new CardIdGenerator())->generate('TEST', static fn (CardId $candidate): bool => true);
    }

    public function testIndependentBranchesFromTheSameStateDoNotShareAnId(): void
    {
        // Same starting state (empty board), independent entropy: distinct IDs.
        $a = (new CardIdGenerator())->generate('TEST', static fn (CardId $candidate): bool => false);
        $b = (new CardIdGenerator())->generate('TEST', static fn (CardId $candidate): bool => false);

        self::assertFalse($a->equals($b));
    }

    public function testBranchHashUsesOnlyTwentyNumericBitsAndPreservesTheCardFormat(): void
    {
        $branch = 'feature/ldap-impact-simulator';
        $id = (new CardIdGenerator(new Randomizer(new Mt19937(42))))->generate(
            'TEST',
            static fn (CardId $candidate): bool => false,
            $branch,
        );

        $hash = (int) hexdec(substr(hash('sha256', $branch), 0, 5));
        $randomMask = (1 << 43) - 1;

        self::assertSame($hash, $id->number >> 43);
        self::assertGreaterThanOrEqual(1, $id->number & $randomMask);
        self::assertLessThanOrEqual($randomMask, $id->number & $randomMask);
        self::assertLessThanOrEqual(PHP_INT_MAX, $id->number);
        self::assertSame('TEST-' . $id->number, $id->toString());
        self::assertTrue(CardId::fromString($id->toString())->equals($id));
    }

    public function testDifferentBranchesKeepDistinctNamespacesEvenWithIdenticalRandomSuffixes(): void
    {
        $exists = static fn (CardId $candidate): bool => false;
        $first = (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', $exists, 'feature/ldap-impact-simulator');
        $second = (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', $exists, 'feature/permission-search');

        self::assertNotSame($first->number >> 43, $second->number >> 43);
        self::assertSame($first->number & ((1 << 43) - 1), $second->number & ((1 << 43) - 1));
        self::assertNotSame($first->toString(), $second->toString());
    }

    public function testSameBranchUsesFreshRandomSuffixesAndRetriesOccupiedIds(): void
    {
        $branch = 'feature/shared-worktree';
        $first = (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', static fn (CardId $candidate): bool => false, $branch);
        $retried = (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', static fn (CardId $candidate): bool => $candidate->equals($first), $branch);

        self::assertNotSame($first->toString(), $retried->toString());
        self::assertSame($first->number >> 43, $retried->number >> 43);
    }

    public function testMissingBranchContextKeepsExistingUniformRandomGenerator(): void
    {
        $expected = (new Randomizer(new Mt19937(42)))->getInt(1, PHP_INT_MAX);
        $exists = static fn (CardId $candidate): bool => false;

        self::assertSame($expected, (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', $exists)->number);
        self::assertSame($expected, (new CardIdGenerator(new Randomizer(new Mt19937(42))))
            ->generate('TEST', $exists, '')->number);
    }

    public function testRefusesA32BitBuildInsteadOfSilentlyShrinkingTheIdSpace(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('64-bit PHP build');

        (new CardIdGenerator(new Randomizer(), 4))->generate('TEST', static fn (CardId $candidate): bool => false);
    }
}
