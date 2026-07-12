<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Exception\IoException;
use voku\AgentKanban\Exception\NotFoundException;
use voku\AgentKanban\Exception\ValidationException;

final class MarkdownCardRepository
{
    private const string FILENAME_PATTERN = '/^([A-Za-z][A-Za-z0-9]*-[0-9]+)\.md$/';

    private readonly CardParser $parser;
    private readonly CardSerializer $serializer;
    private readonly string $normalizedRootPath;

    public function __construct(
        private readonly string $rootPath,
        private readonly BoardConfig $config,
        ?CardParser $parser = null,
        ?CardSerializer $serializer = null,
    ) {
        $this->parser = $parser ?? new CardParser();
        $this->serializer = $serializer ?? new CardSerializer();

        $root = realpath($rootPath);
        if ($root === false || !is_dir($root)) {
            throw new IoException(
                sprintf('Board root does not exist or is not a directory: %s', $rootPath),
                path: $rootPath,
            );
        }

        $this->normalizedRootPath = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function resolveCardDirectory(): ?string
    {
        if (is_dir($this->absolutePath($this->config->cardDirectory))) {
            return $this->config->cardDirectory;
        }

        if (is_dir($this->absolutePath($this->config->legacyCardDirectory))) {
            return $this->config->legacyCardDirectory;
        }

        return null;
    }

    public function directoryForNewCard(): string
    {
        return $this->config->cardDirectory;
    }

    /** @return list<string> */
    public function listCardFiles(): array
    {
        $directory = $this->resolveCardDirectory();
        if ($directory === null) {
            return [];
        }

        $files = glob($this->absolutePath($directory) . '/*.md');
        if ($files === false) {
            throw new IoException('Could not list card files.', path: $this->absolutePath($directory));
        }

        sort($files);

        return $files;
    }

    public function loadAll(): CardCollection
    {
        $cards = [];
        foreach ($this->listCardFiles() as $file) {
            $cards[] = $this->parseFile($file);
        }

        return CardCollection::fromArray($cards);
    }

    public function loadAllLenient(): CardLoadResult
    {
        $cards = [];
        $failures = [];
        $seenIds = [];

        foreach ($this->listCardFiles() as $file) {
            try {
                $card = $this->parseFile($file);
            } catch (ValidationException $exception) {
                $failures[] = new CardLoadFailure(
                    $this->relativePath($file),
                    $exception->getMessage(),
                    $exception->cardId,
                    $exception->field,
                );

                continue;
            }

            $id = $card->id->toString();
            if (isset($seenIds[$id])) {
                $failures[] = new CardLoadFailure(
                    $this->relativePath($file),
                    sprintf('Duplicate card ID "%s" (already defined in %s).', $id, $seenIds[$id]),
                    $id,
                );

                continue;
            }

            $seenIds[$id] = $this->relativePath($file);
            $cards[] = $card;
        }

        return new CardLoadResult(CardCollection::fromArray($cards), $failures);
    }

    public function load(CardId $id): Card
    {
        $path = $this->findExistingPath($id);
        if ($path === null) {
            throw new NotFoundException(sprintf('Card not found: %s', $id), cardId: $id->toString());
        }

        return $this->parseFile($path);
    }

    public function exists(CardId $id): bool
    {
        return $this->findExistingPath($id) !== null;
    }

    public function findExistingPath(CardId $id): ?string
    {
        foreach ([$this->config->cardDirectory, $this->config->legacyCardDirectory] as $directory) {
            $candidate = $this->absolutePath($directory . '/' . $id->toString() . '.md');
            if (is_file($candidate) && !is_link($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function pathForNewCard(CardId $id): string
    {
        return $this->absolutePath($this->config->cardDirectory . '/' . $id->toString() . '.md');
    }

    public function readRaw(string $absolutePath): string
    {
        $this->assertPathInsideRoot($absolutePath);
        $this->assertNoSymlinkComponents($absolutePath);

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new IoException(sprintf('Could not read card file: %s', $absolutePath), path: $absolutePath);
        }

        return $content;
    }

    public function serialize(Card $card): string
    {
        return $this->serializer->serialize($card);
    }

    public function atomicWrite(
        string $absolutePath,
        string $content,
        ?CardRevision $expectedRevision = null,
        bool $mustNotExist = false,
    ): void {
        $this->assertPathInsideRoot($absolutePath);
        $this->assertNoSymlinkComponents($absolutePath);
        $this->ensureSafeDirectory(dirname($absolutePath));

        $lock = $this->openLock($absolutePath);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new IoException(sprintf('Could not lock card file: %s', $absolutePath), path: $absolutePath);
            }

            clearstatcache(true, $absolutePath);
            $this->assertNoSymlinkComponents($absolutePath);

            if ($mustNotExist && file_exists($absolutePath)) {
                throw new ConflictException(
                    sprintf('Card file already exists: %s', $this->relativePath($absolutePath)),
                    cardId: $this->cardIdFromPath($absolutePath),
                );
            }

            $this->assertExpectedRevision($absolutePath, $expectedRevision);
            $this->replaceAtomically($absolutePath, $content);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function moveFile(
        string $absoluteFrom,
        string $absoluteTo,
        ?CardRevision $expectedRevision = null,
    ): void {
        $this->assertPathInsideRoot($absoluteFrom);
        $this->assertPathInsideRoot($absoluteTo);
        $this->assertNoSymlinkComponents($absoluteFrom);
        $this->assertNoSymlinkComponents($absoluteTo);
        $this->ensureSafeDirectory(dirname($absoluteTo));

        $lock = $this->openLock($absoluteFrom);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new IoException(sprintf('Could not lock card file: %s', $absoluteFrom), path: $absoluteFrom);
            }

            $this->assertNoSymlinkComponents($absoluteFrom);
            $this->assertNoSymlinkComponents($absoluteTo);

            if (file_exists($absoluteTo)) {
                throw new ConflictException(
                    sprintf('Destination already exists: %s', $this->relativePath($absoluteTo)),
                    cardId: $this->cardIdFromPath($absoluteFrom),
                );
            }

            $this->assertExpectedRevision($absoluteFrom, $expectedRevision);

            if (!rename($absoluteFrom, $absoluteTo)) {
                throw new IoException(
                    sprintf('Could not move %s to %s', $absoluteFrom, $absoluteTo),
                    path: $absoluteFrom,
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function deleteFile(string $absolutePath): void
    {
        $this->assertPathInsideRoot($absolutePath);
        $this->assertNoSymlinkComponents($absolutePath);

        if (is_file($absolutePath) && !unlink($absolutePath)) {
            throw new IoException(sprintf('Could not delete card file: %s', $absolutePath), path: $absolutePath);
        }
    }

    private function assertExpectedRevision(string $path, ?CardRevision $expected): void
    {
        if ($expected === null) {
            return;
        }

        if (!is_file($path)) {
            throw new ConflictException(
                sprintf('Card file disappeared before mutation: %s', $this->relativePath($path)),
                cardId: $this->cardIdFromPath($path),
                expectedRevision: $expected->toString(),
            );
        }

        $current = str_replace(["\r\n", "\r"], "\n", $this->readRaw($path));
        $actual = CardRevision::fromContent($current);
        if (!$expected->equals($actual)) {
            throw new ConflictException(
                sprintf('Card changed before mutation: %s', $this->relativePath($path)),
                cardId: $this->cardIdFromPath($path),
                expectedRevision: $expected->toString(),
                actualRevision: $actual->toString(),
            );
        }
    }

    private function replaceAtomically(string $target, string $content): void
    {
        $temporaryPath = dirname($target)
            . '/.' . basename($target)
            . '.' . bin2hex(random_bytes(6))
            . '.tmp';

        $handle = fopen($temporaryPath, 'x');
        if ($handle === false) {
            throw new IoException(sprintf('Could not create temporary file: %s', $temporaryPath), path: $temporaryPath);
        }

        $complete = false;
        try {
            $this->writeAll($handle, $content, $temporaryPath);
            if (!fflush($handle)) {
                throw new IoException(sprintf('Could not flush temporary file: %s', $temporaryPath), path: $temporaryPath);
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        if (!rename($temporaryPath, $target)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new IoException(sprintf('Could not atomically replace card file: %s', $target), path: $target);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content, string $path): void
    {
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new IoException(
                    sprintf('Could not completely write temporary file: %s', $path),
                    path: $path,
                );
            }

            $offset += $written;
        }
    }

    /** @return resource */
    private function openLock(string $absolutePath)
    {
        $lockPath = dirname($absolutePath) . '/.' . basename($absolutePath) . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new IoException(sprintf('Could not open lock file: %s', $lockPath), path: $lockPath);
        }

        return $lock;
    }

    private function ensureSafeDirectory(string $directory): void
    {
        $this->assertPathInsideRoot($directory);
        $relative = ltrim(substr(str_replace('\\', '/', $directory), strlen($this->normalizedRootPath)), '/');
        $current = $this->normalizedRootPath;

        foreach (array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '') as $part) {
            $current .= '/' . $part;

            if (is_link($current)) {
                throw new IoException(sprintf('Refusing to use symlinked directory: %s', $current), path: $current);
            }

            if (!file_exists($current) && !mkdir($current, 0o777) && !is_dir($current)) {
                throw new IoException(sprintf('Could not create card directory: %s', $current), path: $current);
            }

            if (!is_dir($current)) {
                throw new IoException(sprintf('Path component is not a directory: %s', $current), path: $current);
            }
        }
    }

    private function assertNoSymlinkComponents(string $absolutePath): void
    {
        $this->assertPathInsideRoot($absolutePath);
        $relative = ltrim(substr(str_replace('\\', '/', $absolutePath), strlen($this->normalizedRootPath)), '/');
        $current = $this->normalizedRootPath;

        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }

            $current .= '/' . $part;
            if (is_link($current)) {
                throw new IoException(sprintf('Refusing to follow symlinked path: %s', $current), path: $current);
            }

            if (!file_exists($current)) {
                break;
            }
        }
    }

    private function assertPathInsideRoot(string $absolutePath): void
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        if ($normalized !== $this->normalizedRootPath
            && !str_starts_with($normalized, $this->normalizedRootPath . '/')) {
            throw new IoException(sprintf('Path escapes the board root: %s', $absolutePath), path: $absolutePath);
        }
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->normalizedRootPath . '/' . $relativePath;
    }

    private function cardIdFromPath(string $path): ?string
    {
        $basename = basename($path, '.md');

        return preg_match('/^[A-Za-z][A-Za-z0-9]*-[0-9]+$/', $basename) === 1
            ? strtoupper($basename)
            : null;
    }

    private function fallbackIdFromFilename(string $file): ?string
    {
        return preg_match(self::FILENAME_PATTERN, basename($file), $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }

    private function parseFile(string $file): Card
    {
        return $this->parser->parse(
            $this->readRaw($file),
            $this->relativePath($file),
            $this->fallbackIdFromFilename($file),
        );
    }

    private function relativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        if (str_starts_with($normalized, $this->normalizedRootPath . '/')) {
            return substr($normalized, strlen($this->normalizedRootPath) + 1);
        }

        return $normalized;
    }
}
