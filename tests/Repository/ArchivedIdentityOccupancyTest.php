<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Repository;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardIdGenerator;
use voku\AgentKanban\Repository\MarkdownCardRepository;

/** Generated IDs must treat an archived card's ID as taken, not just the active directory. */
final class ArchivedIdentityOccupancyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-kanban-occupancy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/todo/cards', 0o775, true);
        mkdir($this->root . '/todo/archive', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testArchivedActiveAndLegacyIdsAreOccupiedAndUnknownIdsAreNot(): void
    {
        $repository = new MarkdownCardRepository($this->root, new BoardConfig('ABC', archiveDirectory: 'todo/archive'));
        file_put_contents($this->root . '/todo/archive/ABC-7.md', "# ABC-7: archived\n");
        file_put_contents($this->root . '/todo/cards/ABC-8.md', "# ABC-8: active\n");

        self::assertTrue($repository->isIdentityOccupied(CardId::fromString('ABC-7')), 'archived');
        self::assertTrue($repository->isIdentityOccupied(CardId::fromString('ABC-8')), 'active');
        self::assertFalse($repository->isIdentityOccupied(CardId::fromString('ABC-9')));
    }

    public function testGeneratorSkipsAnIdThatOnlyExistsInTheArchive(): void
    {
        $repository = new MarkdownCardRepository($this->root, new BoardConfig('ABC', archiveDirectory: 'todo/archive'));
        $firstCandidate = CardId::of('ABC', (new Randomizer(new Mt19937(99)))->getInt(1, PHP_INT_MAX));
        file_put_contents($this->root . '/todo/archive/' . $firstCandidate->toString() . '.md', "# archived\n");

        $generated = (new CardIdGenerator(new Randomizer(new Mt19937(99))))->generate('ABC', $repository->isIdentityOccupied(...));

        self::assertFalse($generated->equals($firstCandidate), 'An archived ID must never be handed out again.');
    }

    public function testBoardWithoutArchiveDirectoryOnlyChecksLiveCards(): void
    {
        $repository = new MarkdownCardRepository($this->root, new BoardConfig('ABC'));

        self::assertFalse($repository->isIdentityOccupied(CardId::fromString('ABC-1')));
    }
}
