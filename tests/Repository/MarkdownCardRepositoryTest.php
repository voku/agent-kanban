<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Repository;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Exception\IoException;
use voku\AgentKanban\Exception\NotFoundException;
use voku\AgentKanban\Repository\MarkdownCardRepository;

final class MarkdownCardRepositoryTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testResolveCardDirectoryPrefersCardsOverJira(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        mkdir($root . '/todo/jira', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', $this->minimalCard('ABC-1', 'READY'));
        file_put_contents($root . '/todo/jira/ABC-2.md', $this->minimalCard('ABC-2', 'READY'));
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        self::assertSame('todo/cards', $repository->resolveCardDirectory());
        self::assertTrue($repository->loadAll()->has('ABC-1'));
        self::assertFalse($repository->loadAll()->has('ABC-2'));
    }

    public function testResolveCardDirectoryFallsBackToLegacy(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/jira', 0o777, true);
        file_put_contents($root . '/todo/jira/ABC-1.md', $this->minimalCard('ABC-1', 'READY'));
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        self::assertSame('todo/jira', $repository->resolveCardDirectory());
        self::assertTrue($repository->loadAll()->has('ABC-1'));
    }

    public function testResolveCardDirectoryNullWhenNeitherExists(): void
    {
        $root = $this->makeTempBoard();
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        self::assertNull($repository->resolveCardDirectory());
        self::assertCount(0, $repository->loadAll());
    }

    public function testLoadThrowsNotFoundForMissingCard(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $this->expectException(NotFoundException::class);
        $repository->load(CardId::fromString('ABC-999'));
    }

    public function testLoadAllLenientCollectsMalformedCardsAsFailures(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', $this->minimalCard('ABC-1', 'READY'));
        file_put_contents($root . '/todo/cards/ABC-2.md', "# ABC-2: Broken\n\n- **Ticket:** ABC-2\n");
        $result = (new MarkdownCardRepository($root, BoardConfig::default('ABC')))->loadAllLenient();
        self::assertCount(1, $result->cards);
        self::assertCount(1, $result->failures);
        self::assertSame('todo/cards/ABC-2.md', $result->failures[0]->file);
    }

    public function testLoadAllStrictThrowsOnFirstMalformedCard(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', "# ABC-1: Broken\n\n- **Ticket:** ABC-1\n");
        $this->expectException(\voku\AgentKanban\Exception\ValidationException::class);
        (new MarkdownCardRepository($root, BoardConfig::default('ABC')))->loadAll();
    }

    public function testLoadAllLenientDetectsDuplicateCardIds(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', $this->minimalCard('ABC-1', 'READY'));
        file_put_contents($root . '/todo/cards/ABC-1-dup.md', $this->minimalCard('ABC-1', 'DOING'));
        $result = (new MarkdownCardRepository($root, BoardConfig::default('ABC')))->loadAllLenient();
        self::assertCount(1, $result->cards);
        self::assertCount(1, $result->failures);
        self::assertStringContainsString('Duplicate card ID', $result->failures[0]->message);
    }

    public function testAtomicWriteThenReadBack(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $path = $root . '/todo/cards/ABC-1.md';
        $content = "# ABC-1: X\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n";
        $repository->atomicWrite($path, $content);
        self::assertSame($content, $repository->readRaw($path));
        self::assertSame([], glob($root . '/todo/cards/*.tmp') ?: []);
    }

    public function testAtomicWritePreservesOriginalFileNameEvenAfterMultipleWrites(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $path = $root . '/todo/cards/ABC-1.md';
        $repository->atomicWrite($path, 'v1');
        $repository->atomicWrite($path, 'v2');
        self::assertSame('v2', $repository->readRaw($path));
        self::assertSame([], glob($root . '/todo/cards/*.tmp') ?: []);
    }

    public function testAtomicWriteRefusesSymlinkTarget(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is not available.');
        }
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents($root . '/todo/cards/real.md', 'real content');
        symlink($root . '/todo/cards/real.md', $root . '/todo/cards/link.md');
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $this->expectException(IoException::class);
        $repository->atomicWrite($root . '/todo/cards/link.md', 'new content');
    }

    public function testMoveFileRefusesToOverwriteExistingDestination(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        mkdir($root . '/todo/archive', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', 'source');
        file_put_contents($root . '/todo/archive/ABC-1.md', 'already archived');
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $this->expectException(ConflictException::class);
        $repository->moveFile($root . '/todo/cards/ABC-1.md', $root . '/todo/archive/ABC-1.md');
    }

    public function testMoveFileSucceeds(): void
    {
        $root = $this->makeTempBoard();
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents($root . '/todo/cards/ABC-1.md', 'content');
        $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
        $repository->moveFile($root . '/todo/cards/ABC-1.md', $root . '/todo/archive/ABC-1.md');
        self::assertFileDoesNotExist($root . '/todo/cards/ABC-1.md');
        self::assertFileExists($root . '/todo/archive/ABC-1.md');
    }

    private function minimalCard(string $id, string $lane): string
    {
        return "# {$id}: Title\n\n- **Ticket:** {$id}\n- **Lane:** {$lane}\n";
    }

    private function makeTempBoard(): string
    {
        $dir = sys_get_temp_dir() . '/agent_kanban_repo_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($dir);
    }
}
