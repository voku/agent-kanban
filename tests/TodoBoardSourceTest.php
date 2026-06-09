<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\TodoBoardSource;

final class TodoBoardSourceTest extends TestCase
{
    public function testReadBoardMarkdown(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $source = new TodoBoardSource($rootPath);
        $markdown = $source->readBoardMarkdown();

        $this->assertStringContainsString('# TODO for Coding Agents', $markdown);
        $this->assertStringContainsString('ITPNG-100', $markdown);
        $this->assertStringContainsString('ITPNG-101', $markdown);
        $this->assertStringContainsString('ITPNG-102', $markdown);
        $this->assertStringContainsString('ITPNG-103', $markdown);
        $this->assertStringContainsString('ITPNG-104', $markdown);
    }

    public function testReadIndexMarkdown(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $source = new TodoBoardSource($rootPath);
        $markdown = $source->readIndexMarkdown();

        $this->assertStringContainsString('This project uses a split-file Kanban board', $markdown);
    }

    public function testReadBoardMarkdownWithCustomPrefixPassedToConstructor(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $source = new TodoBoardSource($rootPath, 'FOO');
        $markdown = $source->readBoardMarkdown();

        $this->assertStringContainsString('## FOO Markdown Board', $markdown);
        $this->assertStringNotContainsString('ITPNG-100', $markdown);
    }

    public function testReadBoardMarkdownWithPrefixFromMetadata(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/todo/jira', 0777, true);

        file_put_contents($tempDir . '/TODO.md', 'This project uses a split-file Kanban board');
        file_put_contents($tempDir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 5\n");
        file_put_contents($tempDir . '/todo/jira/ABC-123.md', "# ABC-123: Test Task\n\n- **Ticket:** ABC-123\n- **Lane:** READY\n- **Status:** Selected\n- **Domain:** M365\n- **Assignee:** test\n- **Updated:** 2026-06-09\n- **Fit:** test\n\n## Agent Task Brief\nTest brief\n");

        $source = new TodoBoardSource($tempDir);
        $markdown = $source->readBoardMarkdown();

        $this->assertStringContainsString('## ABC Markdown Board', $markdown);
        $this->assertStringContainsString('ABC-123', $markdown);
        $this->assertStringContainsString('Test Task', $markdown);

        // cleanup
        unlink($tempDir . '/todo/jira/ABC-123.md');
        unlink($tempDir . '/todo/board.md');
        unlink($tempDir . '/TODO.md');
        rmdir($tempDir . '/todo/jira');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }
}
