<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentKanban\Cli\CliApplication;

final class CardCreateTaskBriefTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-create-brief-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/todo/cards', 0o775, true);
        file_put_contents(
            $this->root . '/todo/kanban.config.json',
            json_encode([
                'projectPrefix' => 'TEST',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCreateCanAtomicallyProduceVerifierValidReadyCardWithTaskBrief(): void
    {
        $create = $this->runCli([
            'agent-kanban',
            'card',
            'create',
            'TEST-1',
            '--title=Implement the change',
            '--lane=READY',
            '--status=Selected',
            '--summary=Ready for governed work.',
            '--brief=Change one verified behavior and record the evidence.',
        ]);

        self::assertSame(CliApplication::EXIT_OK, $create['exit'], $create['output']);
        self::assertFileExists($this->root . '/todo/cards/TEST-1.md');
        self::assertStringContainsString(
            'Change one verified behavior and record the evidence.',
            (string) file_get_contents($this->root . '/todo/cards/TEST-1.md'),
        );

        $verify = $this->runCli(['agent-kanban', 'verify']);
        self::assertSame(CliApplication::EXIT_OK, $verify['exit'], $verify['output']);
        self::assertStringContainsString('Board verification passed.', $verify['output']);
    }

    public function testCreateWithoutBriefKeepsExistingEmptyDefaultOutsideRequiredLane(): void
    {
        $create = $this->runCli([
            'agent-kanban',
            'card',
            'create',
            'TEST-2',
            '--title=Backlog item',
            '--lane=BACKLOG',
        ]);

        self::assertSame(CliApplication::EXIT_OK, $create['exit'], $create['output']);

        $show = $this->runCli([
            'agent-kanban',
            'card',
            'show',
            'TEST-2',
            '--format=json',
        ]);
        self::assertSame(CliApplication::EXIT_OK, $show['exit'], $show['output']);
        $payload = json_decode($show['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $payload['card']['taskBrief'] ?? null);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function runCli(array $argv): array
    {
        ob_start();
        $exit = (new CliApplication($this->root))->run($argv);
        $stdout = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $stdout];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
