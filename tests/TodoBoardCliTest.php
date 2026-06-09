<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\TodoBoardCli;

final class TodoBoardCliTest extends TestCase
{
    public function testSummary(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $cli = new TodoBoardCli($rootPath);

        $this->expectOutputRegex('/TODO board summary/');
        $exitCode = $cli->run(['bin/agent-kanban', 'summary']);

        $this->assertSame(0, $exitCode);
    }

    public function testLaneReady(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $cli = new TodoBoardCli($rootPath);

        $this->expectOutputRegex('/ITPNG-100/');
        $exitCode = $cli->run(['bin/agent-kanban', 'lane', 'READY']);

        $this->assertSame(0, $exitCode);
    }

    public function testBrief(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $cli = new TodoBoardCli($rootPath);

        $this->expectOutputRegex('/Ready Task Brief/');
        $exitCode = $cli->run(['bin/agent-kanban', 'brief', 'ITPNG-100']);

        $this->assertSame(0, $exitCode);
    }

    public function testTicket(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $cli = new TodoBoardCli($rootPath);

        $this->expectOutputRegex('/ITPNG-100/');
        $exitCode = $cli->run(['bin/agent-kanban', 'ticket', 'ITPNG-100']);

        $this->assertSame(0, $exitCode);
    }
}
