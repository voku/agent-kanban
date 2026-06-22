<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\TodoBoardVerifier;

final class TodoBoardVerifierTest extends TestCase
{
    public function testVerifierPassesOnValidFixture(): void
    {
        $rootPath = __DIR__ . '/fixtures/project-root';
        $verifier = new TodoBoardVerifier($rootPath);
        
        // Suppress stdout output during the test execution.
        $this->expectOutputRegex('/TODO board verification passed\./');
        $exitCode = $verifier->run();

        $this->assertSame(0, $exitCode);
    }

    public function testVerifierPassesOnCustomPrefix(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_verifier_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/todo/jira', 0777, true);

        // Create valid structure
        file_put_contents($tempDir . '/TODO.md', "This project uses a split-file Kanban board under todo/jira/\n");
        file_put_contents($tempDir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `CUSTOM`\n- **Done count:** 0\n");
        file_put_contents($tempDir . '/todo/jira/CUSTOM-100.md', "# CUSTOM-100: Ready Task\n\n- **Ticket:** CUSTOM-100\n- **Lane:** READY\n- **Status:** Selected\n- **Domain:** M365\n- **Assignee:** test\n- **Updated:** 2026-06-09\n- **Fit:** test\n- **Next:** test\n- **Validation:** test\n- **Wave:** Wave 1\n\n## Agent Task Brief\n#### CUSTOM-100: Ready Task Brief\nReady Task Brief\n");

        $verifier = new TodoBoardVerifier($tempDir);

        $this->expectOutputRegex('/TODO board verification passed\./');
        $exitCode = $verifier->run();

        $this->assertSame(0, $exitCode);

        // cleanup
        unlink($tempDir . '/todo/jira/CUSTOM-100.md');
        unlink($tempDir . '/todo/board.md');
        unlink($tempDir . '/TODO.md');
        rmdir($tempDir . '/todo/jira');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }

    public function testVerifierPassesOnPreferredCardsDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_verifier_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/todo/cards', 0777, true);

        file_put_contents($tempDir . '/TODO.md', "This project uses a split-file Kanban board under todo/cards/\n");
        file_put_contents($tempDir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `CUSTOM`\n- **Done count:** 0\n");
        file_put_contents($tempDir . '/todo/cards/CUSTOM-100.md', "# CUSTOM-100: Ready Task\n\n- **Ticket:** CUSTOM-100\n- **Lane:** READY\n- **Status:** Selected\n- **Domain:** M365\n- **Assignee:** test\n- **Updated:** 2026-06-09\n- **Fit:** test\n- **Next:** test\n- **Validation:** test\n- **Wave:** Wave 1\n\n## Agent Task Brief\n#### CUSTOM-100: Ready Task Brief\nReady Task Brief\n");

        $verifier = new TodoBoardVerifier($tempDir);

        $this->expectOutputRegex('/TODO board verification passed\./');
        $exitCode = $verifier->run();

        $this->assertSame(0, $exitCode);

        // cleanup
        unlink($tempDir . '/todo/cards/CUSTOM-100.md');
        unlink($tempDir . '/todo/board.md');
        unlink($tempDir . '/TODO.md');
        rmdir($tempDir . '/todo/cards');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }

    public function testVerifierFailsWhenIndexStillPointsAtJiraButCardsLiveUnderPreferredDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/agent_kanban_test_verifier_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/todo/cards', 0777, true);

        file_put_contents($tempDir . '/TODO.md', "This project uses a split-file Kanban board under todo/jira/\n");
        file_put_contents($tempDir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `CUSTOM`\n- **Done count:** 0\n");
        file_put_contents($tempDir . '/todo/cards/CUSTOM-100.md', "# CUSTOM-100: Ready Task\n\n- **Ticket:** CUSTOM-100\n- **Lane:** READY\n- **Status:** Selected\n- **Domain:** M365\n- **Assignee:** test\n- **Updated:** 2026-06-09\n- **Fit:** test\n- **Next:** test\n- **Validation:** test\n- **Wave:** Wave 1\n\n## Agent Task Brief\n#### CUSTOM-100: Ready Task Brief\nReady Task Brief\n");

        $verifier = new TodoBoardVerifier($tempDir);

        $exitCode = $verifier->run();

        $this->assertSame(1, $exitCode);

        // cleanup
        unlink($tempDir . '/todo/cards/CUSTOM-100.md');
        unlink($tempDir . '/todo/board.md');
        unlink($tempDir . '/TODO.md');
        rmdir($tempDir . '/todo/cards');
        rmdir($tempDir . '/todo');
        rmdir($tempDir);
    }
}
