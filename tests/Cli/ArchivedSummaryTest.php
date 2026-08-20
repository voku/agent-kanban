<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ArchivedSummaryTest extends TestCase
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

    public function testSummaryDerivesDoneCountFromRealArchiveState(): void
    {
        $root = $this->boardWithArchive();

        self::assertSame(0, $this->doneCount($root));

        $dryArchive = $this->runCli(['card', 'archive', 'ABC-1', '--dry-run'], $root);
        self::assertSame(0, $dryArchive['exitCode']);
        self::assertSame(0, $this->doneCount($root));
        self::assertFileExists($root . '/todo/cards/ABC-1.md');

        $archive = $this->runCli(['card', 'archive', 'ABC-1'], $root);
        self::assertSame(0, $archive['exitCode']);
        self::assertSame(1, $this->doneCount($root));
        self::assertFileExists($root . '/todo/archive/ABC-1.md');

        $caseDistinctDuplicate = $root . '/todo/archive/abc-1.md';
        if (!file_exists($caseDistinctDuplicate)) {
            file_put_contents($caseDistinctDuplicate, $this->minimalCard('ABC-1'));
        }
        file_put_contents($root . '/todo/archive/ABC-2.md', "# ABC-2: Broken\n\n- **Ticket:** ABC-2\n");
        file_put_contents($root . '/todo/archive/XYZ-1.md', $this->minimalCard('XYZ-1'));
        file_put_contents($root . '/todo/archive/ABC-3.md', $this->minimalCard('ABC-4'));
        file_put_contents($root . '/todo/archive/notes.txt', 'not a card');
        self::assertSame(1, $this->doneCount($root));

        $dryRestore = $this->runCli(['card', 'restore', 'ABC-1', '--dry-run'], $root);
        self::assertSame(0, $dryRestore['exitCode']);
        self::assertSame(1, $this->doneCount($root));
        self::assertFileExists($root . '/todo/archive/ABC-1.md');

        $restore = $this->runCli(['card', 'restore', 'ABC-1'], $root);
        self::assertSame(0, $restore['exitCode']);
        self::assertSame(0, $this->doneCount($root));
        self::assertFileExists($root . '/todo/cards/ABC-1.md');
    }

    private function boardWithArchive(): string
    {
        $dir = sys_get_temp_dir() . '/agent_kanban_archive_summary_' . bin2hex(random_bytes(6));
        mkdir($dir . '/todo/cards', 0o777, true);
        file_put_contents(
            $dir . '/todo/kanban.config.json',
            json_encode(
                ['projectPrefix' => 'ABC', 'archiveDirectory' => 'todo/archive'],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
        file_put_contents(
            $dir . '/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 99\n",
        );
        file_put_contents($dir . '/todo/cards/ABC-1.md', $this->minimalCard('ABC-1'));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function minimalCard(string $id): string
    {
        return "# {$id}: Title\n\n- **Ticket:** {$id}\n- **Lane:** BACKLOG\n";
    }

    private function doneCount(string $root): int
    {
        $result = $this->runCli(['summary', '--format=json'], $root);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('doneCount', $decoded);
        self::assertIsInt($decoded['doneCount']);

        return $decoded['doneCount'];
    }

    /**
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runCli(array $args, string $cwd): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-kanban';
        $args[] = '--root=' . $cwd;
        $command = array_merge(['php', $bin], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($escaped, $descriptors, $pipes, $cwd);
        self::assertNotFalse($process);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
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
