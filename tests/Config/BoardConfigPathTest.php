<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Exception\ConfigurationException;

final class BoardConfigPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent traversal' => ['../../outside'];
        yield 'embedded traversal' => ['todo/../outside'];
        yield 'absolute unix path' => ['/tmp/cards'];
        yield 'absolute windows path' => ['C:/tmp/cards'];
        yield 'backslash path' => ['todo\\cards'];
        yield 'empty component' => ['todo//cards'];
        yield 'current component' => ['todo/./cards'];
        yield 'nul byte' => ["todo/cards\0outside"];
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsUnsafeCardDirectory(string $path): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', cardDirectory: $path);
    }

    public function testAcceptsNestedRepositoryRelativeDirectories(): void
    {
        $config = new BoardConfig(
            'ABC',
            cardDirectory: 'var/agent/cards',
            legacyCardDirectory: 'var/agent/legacy',
            archiveDirectory: 'var/agent/archive',
        );

        self::assertSame('var/agent/cards', $config->cardDirectory);
    }
}
