<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Exception\ValidationException;

/**
 * Shared low-level reader for the `- **Label:** value` bullet-metadata lines
 * used both by card files ({@see CardParser}) and by `todo/board.md`
 * ({@see BoardMetadata}). Kept in one place so the duplicate-field rule and
 * the exact bullet syntax are defined once.
 */
final class BulletMetadata
{
    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    public static function parseLines(array $lines, string $sourceFile): array
    {
        $metadata = [];
        foreach ($lines as $line) {
            if (preg_match('/^-\s*\*\*([^*]+):\*\*\s*(.*)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $label = trim($matches[1]);
            if (isset($metadata[$label])) {
                throw new ValidationException(
                    sprintf('Duplicate metadata field "%s".', $label),
                    cardFile: $sourceFile,
                    field: $label,
                );
            }

            $metadata[$label] = trim($matches[2]);
        }

        return $metadata;
    }

    /**
     * Parses bullet metadata from the start of $content up to (excluding) the
     * first `## ` section heading, or the whole content if there is none.
     *
     * @return array<string, string>
     */
    public static function parseUpToFirstSection(string $content, string $sourceFile): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $normalized);
        $end = self::findSectionBoundary($lines);

        return self::parseLines(array_slice($lines, 0, $end), $sourceFile);
    }

    /**
     * The index of the first `## ` section-heading line, or `count($lines)`
     * if there is none. Shared so {@see CardParser::findMetadataEnd()} and
     * {@see self::parseUpToFirstSection()} apply identical boundary detection.
     *
     * @param list<string> $lines
     */
    public static function findSectionBoundary(array $lines): int
    {
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '## ')) {
                return $index;
            }
        }

        return count($lines);
    }
}
