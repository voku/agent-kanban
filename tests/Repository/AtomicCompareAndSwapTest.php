<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Repository;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Repository\MarkdownCardRepository;

final class AtomicCompareAndSwapTest extends TestCase
{
    public function testWriteRejectsARevisionThatChangedAfterTheCallerReadIt(): void
    {
        $root = sys_get_temp_dir() . '/agent_kanban_cas_' . bin2hex(random_bytes(6));
        mkdir($root . '/todo/cards', 0o777, true);
        $path = $root . '/todo/cards/ABC-1.md';
        $original = "# ABC-1: Original\n\n- **Ticket:** ABC-1\n- **Lane:** BACKLOG\n";
        file_put_contents($path, $original);

        try {
            $repository = new MarkdownCardRepository($root, BoardConfig::default('ABC'));
            $revisionReadByFirstWriter = CardRevision::fromContent($original);

            file_put_contents($path, "# ABC-1: Concurrent edit\n\n- **Ticket:** ABC-1\n- **Lane:** BACKLOG\n");

            $this->expectException(ConflictException::class);
            $repository->atomicWrite($path, 'replacement', $revisionReadByFirstWriter);
        } finally {
            foreach (glob($root . '/todo/cards/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root . '/todo/cards/.*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($root . '/todo/cards');
            rmdir($root . '/todo');
            rmdir($root);
        }
    }
}
