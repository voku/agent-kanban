<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Repository\BoardConfigurationMode;
use voku\AgentKanban\Repository\BoardContextResolver;

final class BoardContextProvenanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-provenance-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/todo/cards', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testJsonConfigurationReportsItsSource(): void
    {
        $path = $this->root . '/todo/kanban.config.json';
        file_put_contents($path, json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR));

        $resolution = (new BoardContextResolver())->resolveWithProvenance($this->root);

        self::assertSame('ABC', $resolution->context->config->projectPrefix);
        self::assertSame(BoardConfigurationMode::JSON, $resolution->configurationMode);
        self::assertSame($path, $resolution->configurationSourcePath);
    }

    public function testMetadataConfigurationReportsItsSource(): void
    {
        $path = $this->root . '/todo/board.md';
        file_put_contents($path, "# Board\n\n- **Project prefix:** META\n");

        $resolution = (new BoardContextResolver())->resolveWithProvenance($this->root);

        self::assertSame('META', $resolution->context->config->projectPrefix);
        self::assertSame(BoardConfigurationMode::METADATA, $resolution->configurationMode);
        self::assertSame($path, $resolution->configurationSourcePath);
    }

    public function testInferredConfigurationHasNoInventedSourcePath(): void
    {
        file_put_contents($this->root . '/todo/cards/inf-1.md', "# placeholder\n");

        $resolution = (new BoardContextResolver())->resolveWithProvenance($this->root);

        self::assertSame('INF', $resolution->context->config->projectPrefix);
        self::assertSame(BoardConfigurationMode::INFERRED, $resolution->configurationMode);
        self::assertNull($resolution->configurationSourcePath);
    }

    public function testOptionalProvenanceReturnsNullWithoutBoardEvidence(): void
    {
        self::assertNull((new BoardContextResolver())->resolveOptionalWithProvenance($this->root));
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
