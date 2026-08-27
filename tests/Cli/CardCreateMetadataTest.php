<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\TestCase;

final class CardCreateMetadataTest extends TestCase
{
    public function testCreatePersistsNextActionAndValidationWithoutSecondMutation(): void
    {
        $root = sys_get_temp_dir() . '/agent_kanban_create_metadata_' . bin2hex(random_bytes(6));
        mkdir($root . '/todo/cards', 0o777, true);
        file_put_contents(
            $root . '/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 0\n",
        );

        try {
            $create = $this->runCli([
                'card', 'create', 'ABC-1',
                '--title=Complete initial card',
                '--lane=BACKLOG',
                '--next=Implement the owner-local fix.',
                '--validation=vendor/bin/phpunit tests/Cli/CardCreateMetadataTest.php',
            ], $root);

            self::assertSame(0, $create['exitCode'], $create['stderr']);

            $show = $this->runCli(['card', 'show', 'ABC-1', '--format=json'], $root);
            self::assertSame(0, $show['exitCode'], $show['stderr']);
            $decoded = json_decode($show['stdout'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $card = $decoded['card'] ?? null;
            self::assertIsArray($card);
            self::assertSame('Implement the owner-local fix.', $card['nextAction'] ?? null);
            self::assertSame(
                'vendor/bin/phpunit tests/Cli/CardCreateMetadataTest.php',
                $card['validation'] ?? null,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @param list<string> $args
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
