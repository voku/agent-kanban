<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\BoardConfigurationWriter;
use voku\AgentKanban\Repository\BoardContextResolver;

final class BoardConfigurationWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-config-writer-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesConventionalConfigurationThroughOwnerBoundary(): void
    {
        $writer = new BoardConfigurationWriter();
        $config = new BoardConfig(projectPrefix: 'ABC', archiveDirectory: 'todo/archive');

        self::assertTrue($writer->writeConventionalIfMissing($this->root, $config));
        self::assertSame(
            $this->root . '/todo/kanban.config.json',
            $writer->conventionalPath($this->root),
        );

        $resolved = (new BoardContextResolver())->resolve($this->root);
        self::assertSame('ABC', $resolved->config->projectPrefix);
        self::assertSame('todo/archive', $resolved->config->archiveDirectory);
    }

    public function testExistingConfigurationIsNeverOverwritten(): void
    {
        $writer = new BoardConfigurationWriter();
        self::assertTrue($writer->writeConventionalIfMissing($this->root, BoardConfig::default('ABC')));
        self::assertFalse($writer->writeConventionalIfMissing($this->root, BoardConfig::default('OTHER')));

        $resolved = (new BoardContextResolver())->resolve($this->root);
        self::assertSame('ABC', $resolved->config->projectPrefix);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
