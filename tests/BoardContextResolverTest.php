<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Repository\BoardContextResolver;

final class BoardContextResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-context-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/todo/cards', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUsesConventionalConfigWithoutConsumerPathKnowledge(): void
    {
        file_put_contents(
            $this->root . '/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR),
        );

        $context = (new BoardContextResolver())->resolve($this->root);

        self::assertSame($this->root, $context->rootPath);
        self::assertSame('ABC', $context->config->projectPrefix);
    }

    public function testExplicitConfigTakesPrecedenceOverConventionalConfig(): void
    {
        file_put_contents(
            $this->root . '/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'OLD'], JSON_THROW_ON_ERROR),
        );
        $explicit = $this->root . '/board-config.json';
        file_put_contents($explicit, json_encode(['projectPrefix' => 'NEW'], JSON_THROW_ON_ERROR));

        $context = (new BoardContextResolver())->resolve($this->root, null, $explicit);

        self::assertSame('NEW', $context->config->projectPrefix);
    }

    public function testExplicitFilesystemRootIsPreserved(): void
    {
        $explicit = $this->root . '/board-config.json';
        file_put_contents($explicit, json_encode(['projectPrefix' => 'ROOT'], JSON_THROW_ON_ERROR));

        $context = (new BoardContextResolver())->resolve($this->root, '/', $explicit);

        self::assertSame('/', $context->rootPath);
        self::assertSame('ROOT', $context->config->projectPrefix);
    }

    public function testFallsBackToBoardMetadata(): void
    {
        file_put_contents(
            $this->root . '/todo/board.md',
            "# Board\n\n- **Project prefix:** META\n\n## Work\n",
        );

        $context = (new BoardContextResolver())->resolve($this->root);

        self::assertSame('META', $context->config->projectPrefix);
    }

    public function testFallsBackToExistingCardPrefix(): void
    {
        file_put_contents($this->root . '/todo/cards/xyz-17.md', "# placeholder\n");

        $context = (new BoardContextResolver())->resolve($this->root);

        self::assertSame('XYZ', $context->config->projectPrefix);
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
