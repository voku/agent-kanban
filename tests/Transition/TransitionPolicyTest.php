<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Transition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Transition\TransitionPolicy;

final class TransitionPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'backlog to ready' => ['BACKLOG', 'READY', true];
        yield 'ready to doing' => ['READY', 'DOING', true];
        yield 'doing to verify' => ['DOING', 'VERIFY', true];
        yield 'verify to doing' => ['VERIFY', 'DOING', true];
        yield 'doing to blocked' => ['DOING', 'BLOCKED', true];
        yield 'ready to blocked' => ['READY', 'BLOCKED', true];
        yield 'blocked to ready' => ['BLOCKED', 'READY', true];
        yield 'blocked to doing' => ['BLOCKED', 'DOING', true];
        yield 'blocked to backlog' => ['BLOCKED', 'BACKLOG', true];
        yield 'same lane is a no-op move' => ['READY', 'READY', true];
        yield 'backlog to doing is not allowed' => ['BACKLOG', 'DOING', false];
        yield 'verify to backlog is not allowed' => ['VERIFY', 'BACKLOG', false];
        yield 'ready to verify is not allowed' => ['READY', 'VERIFY', false];
    }

    #[DataProvider('transitionProvider')]
    public function testDefaultTransitionsMatchDocumentedPolicy(string $from, string $to, bool $expected): void
    {
        $policy = new TransitionPolicy(BoardConfig::default('ABC'));

        self::assertSame($expected, $policy->canTransition(Lane::fromString($from), Lane::fromString($to)));
    }

    public function testValidateThrowsWithAllowedTargetsInMessage(): void
    {
        $policy = new TransitionPolicy(BoardConfig::default('ABC'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Allowed targets from BACKLOG: READY/');
        $policy->validate(Lane::fromString('BACKLOG'), Lane::fromString('VERIFY'));
    }

    public function testCustomTransitionsAreHonored(): void
    {
        $config = new BoardConfig(
            'ABC',
            lanes: ['A', 'B', 'C'],
            requiredFieldsByLane: [],
            transitions: ['A' => ['B'], 'B' => ['C']],
        );
        $policy = new TransitionPolicy($config);

        self::assertTrue($policy->canTransition(Lane::fromString('A'), Lane::fromString('B')));
        self::assertFalse($policy->canTransition(Lane::fromString('A'), Lane::fromString('C')));
    }

    public function testAllowedTargetsReturnsConfiguredList(): void
    {
        $policy = new TransitionPolicy(BoardConfig::default('ABC'));

        self::assertSame(['DOING', 'BLOCKED'], $policy->allowedTargets(Lane::fromString('READY')));
    }
}
