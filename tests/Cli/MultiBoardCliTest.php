<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * The CLI resolved only the default board: --board was rejected as an unknown
 * option and no board id ever reached the resolver. A repository with several
 * configured boards therefore got a passing verify over one of them.
 *
 * @internal
 */
final class MultiBoardCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-multiboard-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/todo/first', 0o775, true);
        mkdir($this->root . '/todo/second', 0o775, true);

        file_put_contents($this->root . '/todo/kanban.config.json', json_encode([
            'defaultBoard' => 'first',
            'boards'       => [
                [
                    'id'            => 'first',
                    'title'         => 'First Board',
                    'projectPrefix' => 'AAA',
                    'cardDirectory' => 'todo/first',
                    'lanes'         => ['BACKLOG', 'READY'],
                ],
                [
                    'id'            => 'second',
                    'title'         => 'Second Board',
                    'projectPrefix' => 'BBB',
                    'cardDirectory' => 'todo/second',
                    'lanes'         => ['BACKLOG', 'READY'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->writeCard('todo/first/AAA-1.md', 'AAA-1', 'BACKLOG');
        $this->writeCard('todo/second/BBB-1.md', 'BBB-1', 'BACKLOG');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBoardOptionSelectsTheNonDefaultBoard(): void
    {
        $result = $this->runCli(['render', '--board=second']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('BBB-1', $result['stdout']);
        self::assertStringNotContainsString('AAA-1', $result['stdout']);
    }

    public function testBoardOptionIsAccepted(): void
    {
        $result = $this->runCli(['summary', '--board=second']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringNotContainsString('is not valid for', $result['stderr']);
    }

    public function testVerifyCoversEveryConfiguredBoard(): void
    {
        $result = $this->runCli(['verify']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('first', $result['stdout']);
        self::assertStringContainsString('second', $result['stdout']);
    }

    public function testVerifyFailsWhenOnlyANonDefaultBoardIsBroken(): void
    {
        // The whole point: previously this returned 0 because the default board
        // was fine and the second board was never read.
        file_put_contents($this->root . '/todo/second/BBB-2.md', "# BBB-2\n\n- **Lane:** NOT_A_LANE\n");

        $result = $this->runCli(['verify']);

        self::assertNotSame(0, $result['exit'], 'a broken non-default board must fail verify');
    }

    public function testVerifyWithBoardMetadataForOneBoardDoesNotFailOtherBoards(): void
    {
        file_put_contents($this->root . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `AAA`\n- **Source:** `todo/first/*.md`\n- **Done count:** 10\n");

        $result = $this->runCli(['verify']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('first', $result['stdout']);
        self::assertStringContainsString('second', $result['stdout']);
    }

    private function writeCard(string $relativePath, string $id, string $lane): void
    {
        file_put_contents($this->root . '/' . $relativePath, <<<CARD
            # {$id}: fixture card

            - **Ticket:** {$id}
            - **Lane:** {$lane}
            - **Status:** Backlog
            - **Summary:** Fixture card for multi-board CLI coverage.

            CARD);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $args): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-kanban';
        $args[] = '--root=' . $this->root;
        $command = implode(' ', array_map('escapeshellarg', array_merge(['php', $bin], $args)));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->root);
        self::assertNotFalse($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
