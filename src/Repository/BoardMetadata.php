<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

/**
 * The small `todo/board.md` metadata file: a done-card counter and optional
 * overrides for the project prefix / source glob. This is board-wide
 * bookkeeping, not a rendered document that gets reparsed — see
 * `docs/architecture.md`.
 */
final readonly class BoardMetadata
{
    public function __construct(
        public int $doneCount,
        public ?string $projectPrefix,
        public ?string $source,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, null, null);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::empty();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return self::empty();
        }

        $metadata = BulletMetadata::parseUpToFirstSection($content, $path);
        $prefix = trim($metadata['Project prefix'] ?? '', " \t\n\r\0\x0B`'\"");

        return new self(
            (int) ($metadata['Done count'] ?? 0),
            $prefix === '' ? null : $prefix,
            $metadata['Source'] ?? null,
        );
    }
}
