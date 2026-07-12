<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Mutation;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Repository\MarkdownCardRepository;

final class CardMutationServiceTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];
    }

    public function testCreateWritesFileInPreferredDirectory(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);

        $result = $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString('Backlog'), 'Title', 'Summary');

        self::assertFalse($result->dryRun);
        self::assertNull($result->previousRevision);
        self::assertFileExists($root . '/todo/cards/ABC-1.md');
        self::assertStringContainsString('Summary', $this->readFile($root . '/todo/cards/ABC-1.md'));
    }

    public function testCreateConflictsIfCardAlreadyExists(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');

        $this->expectException(ConflictException::class);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T2');
    }

    public function testDryRunNeverWrites(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T', 'Original');

        $result = $service->update(CardId::fromString('ABC-1'), summary: 'Changed', dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertStringContainsString('Original', $this->readFile($root . '/todo/cards/ABC-1.md'));
        self::assertStringNotContainsString('Changed', $this->readFile($root . '/todo/cards/ABC-1.md'));
    }

    public function testUpdateOnlyTouchesProvidedFields(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'Title', 'Summary');

        $result = $service->update(CardId::fromString('ABC-1'), priority: 5);

        self::assertSame(['priority'], $result->changedFields);
        self::assertSame('Title', $result->card->title);
        self::assertSame('Summary', $result->card->summary);
        self::assertSame(5, $result->card->priority);
    }

    public function testExpectedRevisionConflict(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');
        $before = file_get_contents($root . '/todo/cards/ABC-1.md');

        try {
            $service->update(CardId::fromString('ABC-1'), summary: 'x', expectedRevision: CardRevision::fromContent('stale'));
            self::fail('Expected ConflictException.');
        } catch (ConflictException $exception) {
            self::assertSame('ABC-1', $exception->cardId);
            self::assertNotNull($exception->expectedRevision);
            self::assertNotNull($exception->actualRevision);
        }

        self::assertSame($before, file_get_contents($root . '/todo/cards/ABC-1.md'));
    }

    public function testOriginalFileIsPreservedWhenValidationFails(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T', 'Original');
        $before = file_get_contents($root . '/todo/cards/ABC-1.md');

        try {
            $service->move(CardId::fromString('ABC-1'), Lane::fromString('VERIFY'));
            self::fail('Expected ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame($before, file_get_contents($root . '/todo/cards/ABC-1.md'));
    }

    public function testMoveProducesTransitionResult(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');

        $result = $service->move(CardId::fromString('ABC-1'), Lane::fromString('READY'), actor: 'codex');

        self::assertNotNull($result->transition);
        self::assertSame('BACKLOG', $result->transition->previousLane->toString());
        self::assertSame('READY', $result->transition->newLane->toString());
        self::assertSame('codex', $result->transition->actor);
    }

    public function testClaimThenConflictingClaimByAnotherActor(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');
        $service->claim(CardId::fromString('ABC-1'), 'codex');

        $this->expectException(ConflictException::class);
        $service->claim(CardId::fromString('ABC-1'), 'other-agent');
    }

    public function testClaimBySameActorIsIdempotent(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');
        $service->claim(CardId::fromString('ABC-1'), 'codex');

        $result = $service->claim(CardId::fromString('ABC-1'), 'codex');
        self::assertSame('codex', $result->card->claim?->actor);
    }

    public function testExpiredClaimMayBeReplaced(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');
        $service->claim(CardId::fromString('ABC-1'), 'codex', expiresAt: new \DateTimeImmutable('-1 hour'));

        $result = $service->claim(CardId::fromString('ABC-1'), 'other-agent');
        self::assertSame('other-agent', $result->card->claim?->actor);
    }

    public function testReleaseByWrongActorConflicts(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');
        $service->claim(CardId::fromString('ABC-1'), 'codex');

        $this->expectException(ConflictException::class);
        $service->release(CardId::fromString('ABC-1'), 'someone-else');
    }

    public function testReleaseUnclaimedCardIsValidationError(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');

        $this->expectException(ValidationException::class);
        $service->release(CardId::fromString('ABC-1'), 'codex');
    }

    public function testMoveToDoingOnClaim(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');

        $result = $service->claim(CardId::fromString('ABC-1'), 'codex', moveToDoing: true);

        self::assertSame('DOING', $result->card->lane->toString());
        self::assertSame(['claim', 'lane'], $result->changedFields);
    }

    public function testClaimWithoutMoveToDoingOnlyReportsClaimAsChanged(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('READY'), CardStatus::fromString(''), 'T');

        $result = $service->claim(CardId::fromString('ABC-1'), 'codex');

        self::assertSame(['claim'], $result->changedFields);
    }

    public function testArchiveRequiresConfiguredDirectory(): void
    {
        $root = $this->tempBoard();
        $service = $this->service($root);
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');

        $this->expectException(ConfigurationException::class);
        $service->archive(CardId::fromString('ABC-1'));
    }

    public function testArchiveAndRestoreRoundTrip(): void
    {
        $root = $this->tempBoard();
        $config = new BoardConfig('ABC', archiveDirectory: 'todo/archive');
        $repository = new MarkdownCardRepository($root, $config);
        $service = new CardMutationService($root, $config, $repository);

        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');
        $service->archive(CardId::fromString('ABC-1'));

        self::assertFileDoesNotExist($root . '/todo/cards/ABC-1.md');
        self::assertFileExists($root . '/todo/archive/ABC-1.md');

        $service->restore(CardId::fromString('ABC-1'));

        self::assertFileExists($root . '/todo/cards/ABC-1.md');
        self::assertFileDoesNotExist($root . '/todo/archive/ABC-1.md');
    }

    public function testArchivedCardReportsTheArchiveDirectoryAsItsSourceFile(): void
    {
        $root = $this->tempBoard();
        $config = new BoardConfig('ABC', archiveDirectory: 'todo/archive');
        $repository = new MarkdownCardRepository($root, $config);
        $service = new CardMutationService($root, $config, $repository);

        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');
        $result = $service->archive(CardId::fromString('ABC-1'));

        self::assertSame('todo/archive/ABC-1.md', $result->card->sourceFile);
    }

    public function testRestoredCardReportsTheActiveDirectoryAsItsSourceFile(): void
    {
        $root = $this->tempBoard();
        $config = new BoardConfig('ABC', archiveDirectory: 'todo/archive');
        $repository = new MarkdownCardRepository($root, $config);
        $service = new CardMutationService($root, $config, $repository);

        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');
        $service->archive(CardId::fromString('ABC-1'));
        $result = $service->restore(CardId::fromString('ABC-1'));

        self::assertSame('todo/cards/ABC-1.md', $result->card->sourceFile);
    }

    public function testRestoreConflictsWhenActiveCardAlreadyExists(): void
    {
        $root = $this->tempBoard();
        $config = new BoardConfig('ABC', archiveDirectory: 'todo/archive');
        $repository = new MarkdownCardRepository($root, $config);
        $service = new CardMutationService($root, $config, $repository);

        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'T');
        $service->archive(CardId::fromString('ABC-1'));
        $service->create(CardId::fromString('ABC-1'), Lane::fromString('BACKLOG'), CardStatus::fromString(''), 'New card with the same ID');

        $this->expectException(ConflictException::class);
        $service->restore(CardId::fromString('ABC-1'));
    }

    private function service(string $root): CardMutationService
    {
        $config = BoardConfig::default('ABC');

        return new CardMutationService($root, $config, new MarkdownCardRepository($root, $config));
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    private function tempBoard(): string
    {
        $dir = sys_get_temp_dir() . '/agent_kanban_mutation_test_' . bin2hex(random_bytes(6));
        mkdir($dir . '/todo/cards', 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }

        rmdir($dir);
    }
}
