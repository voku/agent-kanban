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
        mkdir($tempDir, 0o777, true);
        mkdir($tempDir . '/todo/jira', 0o777, true);

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

    public function testReadBoardMarkdownFromPreferredCardsDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_' . uniqid();
        mkdir($tempDir, 0o777, true);
        mkdir($tempDir . '/todo/cards', 0o777, true);

        file_put_contents($tempDir . '/TODO.md', 'This project uses a split-file Kanban board');
        file_put_contents($tempDir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 5\n");
        file_put_contents($tempDir . '/todo/cards/ABC-123.md', "# ABC-123: Test Task\n\n- **Ticket:** ABC-123\n- **Lane:** READY\n- **Status:** Selected\n- **Domain:** M365\n- **Assignee:** test\n- **Updated:** 2026-06-09\n- **Fit:** test\n\n## Agent Task Brief\nTest brief\n");

        $source = new TodoBoardSource($tempDir);
        $markdown = $source->readBoardMarkdown();

        $this->assertSame('todo/cards', $source->resolveCardDirectory());
        $this->assertStringContainsString('## ABC Markdown Board', $markdown);
        $this->assertStringContainsString('ABC-123', $markdown);
        $this->assertStringContainsString('todo/cards/*.md', $markdown);
        $this->assertStringNotContainsString('todo/jira', $markdown);

        // cleanup
        unlink($tempDir . '/todo/cards/ABC-123.md');
        unlink($tempDir . '/todo/board.md');
        unlink($tempDir . '/TODO.md');
        rmdir($tempDir . '/todo/cards');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }

    public function testPreferredCardsDirectoryWinsOverCompatibleJiraDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_' . uniqid();
        mkdir($tempDir . '/todo/cards', 0o777, true);
        mkdir($tempDir . '/todo/jira', 0o777, true);

        file_put_contents($tempDir . '/todo/cards/ABC-1.md', "# ABC-1: Preferred\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Status:** Selected\n");
        file_put_contents($tempDir . '/todo/jira/ABC-2.md', "# ABC-2: Compatible\n\n- **Ticket:** ABC-2\n- **Lane:** READY\n- **Status:** Selected\n");

        $source = new TodoBoardSource($tempDir, 'ABC');

        $this->assertSame('todo/cards', $source->resolveCardDirectory());

        // cleanup
        unlink($tempDir . '/todo/cards/ABC-1.md');
        unlink($tempDir . '/todo/jira/ABC-2.md');
        rmdir($tempDir . '/todo/cards');
        rmdir($tempDir . '/todo/jira');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }

    public function testResolveCardDirectoryReturnsNullWhenNeitherDirectoryExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_' . uniqid();
        mkdir($tempDir, 0o777, true);

        $source = new TodoBoardSource($tempDir, 'ABC');

        $this->assertNull($source->resolveCardDirectory());

        rmdir($tempDir);
    }
}
