<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the breaking-change decision (see UPGRADING.md): the pre-1.0
 * generated-Markdown classes are deleted, not deprecated. If any of these
 * come back — even as a facade — this test catches it.
 */
final class LegacyApiRemovedTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function removedTypeProvider(): iterable
    {
        yield 'TodoBoardSource' => ['voku\\AgentKanban\\TodoBoardSource'];
        yield 'TodoBoardVerifier' => ['voku\\AgentKanban\\TodoBoardVerifier'];
        yield 'TodoBoardCli' => ['voku\\AgentKanban\\TodoBoardCli'];
        yield 'TodoBoardCard' => ['voku\\AgentKanban\\TodoBoardCard'];
        yield 'TodoBoardRenderOptions' => ['voku\\AgentKanban\\TodoBoardRenderOptions'];
        yield 'JiraIssueProvider' => ['voku\\AgentKanban\\JiraIssueProvider'];
    }

    /**
     * @param class-string $type
     */
    #[DataProvider('removedTypeProvider')]
    public function testRemovedLegacyTypeDoesNotExist(string $type): void
    {
        self::assertFalse(
            class_exists($type) || interface_exists($type),
            sprintf('"%s" should have been removed, not kept as a facade.', $type),
        );
    }
}
