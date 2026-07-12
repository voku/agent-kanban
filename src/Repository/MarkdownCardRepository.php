<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\IoException;
use voku\AgentKanban\Exception\NotFoundException;

/**
 * Reads card files directly into immutable {@see Card} objects and writes
 * them back atomically. This is the *only* place that touches the
 * filesystem for cards; rendering, verification, queries, and mutations all
 * operate on the typed objects this class produces — never on regenerated
 * Markdown (see `docs/architecture.md`).
 *
 * `todo/cards/` is the preferred card directory; `todo/jira/` is read for
 * compatibility with 0.x boards. If both exist, `todo/cards/` wins in full —
 * `todo/jira/` is ignored, never merged, so a board is never ambiguous about
 * which file is authoritative for a given card ID.
 */
final class MarkdownCardRepository
{
    private const string FILENAME_PATTERN = '/^([A-Za-z][A-Za-z0-9]*-[0-9]+)\.md$/';

    private readonly CardParser $parser;

    private readonly CardSerializer $serializer;

    public function __construct(
        private readonly string $rootPath,
        private readonly BoardConfig $config,
        ?CardParser $parser = null,
        ?CardSerializer $serializer = null,
    ) {
        $this->parser = $parser ?? new CardParser();
        $this->serializer = $serializer ?? new CardSerializer();
    }

    /**
     * The card directory that is actually in use: `todo/cards/` if it
     * exists, otherwise `todo/jira/` if that exists, otherwise null (no
     * cards have ever been written for this board yet).
     */
    public function resolveCardDirectory(): ?string
    {
        if (is_dir($this->rootPath . '/' . $this->config->cardDirectory)) {
            return $this->config->cardDirectory;
        }

        if (is_dir($this->rootPath . '/' . $this->config->legacyCardDirectory)) {
            return $this->config->legacyCardDirectory;
        }

        return null;
    }

    /**
     * The directory a brand-new card is written into: always the preferred
     * directory, regardless of whether an existing board currently uses the
     * legacy one for its other cards.
     */
    public function directoryForNewCard(): string
    {
        return $this->config->cardDirectory;
    }

    /**
     * @return list<string> Absolute paths, sorted, to every `*.md` file in the
     *                      resolved card directory (not filtered by prefix —
     *                      that judgment belongs to the verifier).
     */
    public function listCardFiles(): array
    {
        $directory = $this->resolveCardDirectory();
        if ($directory === null) {
            return [];
        }

        $files = glob($this->rootPath . '/' . $directory . '/*.md');
        if ($files === false) {
            throw new IoException('Could not list card files.', path: $this->rootPath . '/' . $directory);
        }

        sort($files);

        return $files;
    }

    /**
     * Strict load: throws on the first structurally invalid card. Use this
     * for rendering, queries, and mutations, which assume a valid board.
     */
    public function loadAll(): CardCollection
    {
        $cards = [];
        foreach ($this->listCardFiles() as $file) {
            $cards[] = $this->parseFile($file);
        }

        return CardCollection::fromArray($cards);
    }

    /**
     * Lenient load: never throws for a single bad card. Use this for
     * `agent-kanban verify`, so one malformed file does not hide every other
     * problem on the board.
     */
    public function loadAllLenient(): CardLoadResult
    {
        $cards = [];
        $failures = [];
        $seenIds = [];

        foreach ($this->listCardFiles() as $file) {
            try {
                $card = $this->parseFile($file);
            } catch (\voku\AgentKanban\Exception\ValidationException $exception) {
                $failures[] = new CardLoadFailure(
                    $this->relativePath($file),
                    $exception->getMessage(),
                    $exception->cardId,
                    $exception->field,
                );

                continue;
            }

            $key = $card->id->toString();
            if (isset($seenIds[$key])) {
                $failures[] = new CardLoadFailure(
                    $this->relativePath($file),
                    sprintf('Duplicate card ID "%s" (already defined in %s).', $key, $seenIds[$key]),
                    $key,
                );

                continue;
            }

            $seenIds[$key] = $this->relativePath($file);
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

    /**
     * Absolute path to $id's file wherever it currently lives (preferred
     * directory searched first, then legacy), or null if it does not exist
     * in either.
     */
    public function findExistingPath(CardId $id): ?string
    {
        foreach ([$this->config->cardDirectory, $this->config->legacyCardDirectory] as $directory) {
            $candidate = $this->rootPath . '/' . $directory . '/' . $id->toString() . '.md';
            if (is_file($candidate) && !is_link($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Absolute path a new card for $id would be written to (always the
     * preferred directory).
     */
    public function pathForNewCard(CardId $id): string
    {
        return $this->rootPath . '/' . $this->config->cardDirectory . '/' . $id->toString() . '.md';
    }

    public function readRaw(string $absolutePath): string
    {
        if (is_link($absolutePath)) {
            throw new IoException('Refusing to read a symlinked card file.', path: $absolutePath);
        }

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

    /**
     * Writes $content to $absolutePath atomically: a temporary sibling file
     * is written and flushed, then renamed over the target. The original
     * file is left untouched if any step fails. Never follows a symlink at
     * the target path.
     */
    public function atomicWrite(string $absolutePath, string $content): void
    {
        if (is_link($absolutePath)) {
            throw new IoException('Refusing to write through a symlinked card path.', path: $absolutePath);
        }

        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new IoException(sprintf('Could not create card directory: %s', $directory), path: $directory);
        }

        $temporaryPath = $directory . '/.' . basename($absolutePath) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $handle = fopen($temporaryPath, 'x');
        if ($handle === false) {
            throw new IoException(sprintf('Could not create temporary file: %s', $temporaryPath), path: $temporaryPath);
        }

        $written = false;

        try {
            if (fwrite($handle, $content) === false) {
                throw new IoException(sprintf('Could not write temporary file: %s', $temporaryPath), path: $temporaryPath);
            }

            if (!fflush($handle)) {
                throw new IoException(sprintf('Could not flush temporary file: %s', $temporaryPath), path: $temporaryPath);
            }

            $written = true;
        } finally {
            fclose($handle);

            if (!$written && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        if (!rename($temporaryPath, $absolutePath)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new IoException(sprintf('Could not atomically replace card file: %s', $absolutePath), path: $absolutePath);
        }
    }

    /**
     * Atomically moves a file (archive / restore). Refuses to overwrite an
     * existing file at the destination and refuses to follow a symlink at
     * either end.
     */
    public function moveFile(string $absoluteFrom, string $absoluteTo): void
    {
        if (is_link($absoluteFrom) || is_link($absoluteTo)) {
            throw new IoException('Refusing to move through a symlinked card path.', path: $absoluteFrom);
        }

        if (is_file($absoluteTo)) {
            throw new IoException(sprintf('Destination already exists: %s', $absoluteTo), path: $absoluteTo);
        }

        $directory = dirname($absoluteTo);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new IoException(sprintf('Could not create directory: %s', $directory), path: $directory);
        }

        if (!rename($absoluteFrom, $absoluteTo)) {
            throw new IoException(sprintf('Could not move %s to %s', $absoluteFrom, $absoluteTo), path: $absoluteFrom);
        }
    }

    public function deleteFile(string $absolutePath): void
    {
        if (is_link($absolutePath)) {
            throw new IoException('Refusing to delete a symlinked card path.', path: $absolutePath);
        }

        if (is_file($absolutePath) && !unlink($absolutePath)) {
            throw new IoException(sprintf('Could not delete card file: %s', $absolutePath), path: $absolutePath);
        }
    }

    /**
     * Derives the loose `<PREFIX>-<NUMBER>` fallback ID a filename implies,
     * regardless of whether the prefix matches this board's configured
     * project prefix (that judgment belongs to the verifier).
     */
    private function fallbackIdFromFilename(string $file): ?string
    {
        return preg_match(self::FILENAME_PATTERN, basename($file), $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }

    private function parseFile(string $file): Card
    {
        $content = $this->readRaw($file);

        return $this->parser->parse($content, $this->relativePath($file), $this->fallbackIdFromFilename($file));
    }

    private function relativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->rootPath . '/')) {
            return substr($absolutePath, strlen($this->rootPath) + 1);
        }

        return $absolutePath;
    }
}
