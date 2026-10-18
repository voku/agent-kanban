<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Cli\CliApplication;

final class VerifyUnresolvedConflictTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-verify-conflict-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/todo/cards', 0o775, true);
        file_put_contents($this->root . '/todo/kanban.config.json', json_encode(['projectPrefix' => 'TEST'], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testVerifyFailsOnACardWithUnresolvedMergeConflictMarkers(): void
    {
        file_put_contents($this->root . '/todo/cards/TEST-3.md', <<<'MD'
            # TEST-3: Follow-up

            - **Ticket:** TEST-3
            - **Lane:** BACKLOG
            - **Status:**

            <<<<<<< HEAD
            ## Summary from branch a
            =======
            ## Summary from branch b
            >>>>>>> branch-b
            MD
            . "\n");

        ob_start();
        $exit = (new CliApplication($this->root))->run(['agent-kanban', 'verify', '--format=json']);
        $output = (string) ob_get_clean();

        self::assertNotSame(CliApplication::EXIT_OK, $exit, $output);
        self::assertStringContainsString('unresolved-merge-conflict', $output);
        self::assertStringContainsString('TEST-3.md', $output);
    }
}
