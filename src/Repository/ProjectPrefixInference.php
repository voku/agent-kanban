<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;

/**
 * Last-resort project-prefix detection: when neither an explicit config nor
 * `todo/board.md` says what the prefix is, infer it from whatever card
 * files already exist. Shared by {@see \voku\AgentKanban\Cli\BoardContextFactory}
 * and the deprecated `voku\AgentKanban\Legacy` facades so the rule is
 * defined exactly once.
 */
final class ProjectPrefixInference
{
    public static function infer(string $rootPath): ?string
    {
        foreach ([BoardConfig::PREFERRED_CARD_DIRECTORY, BoardConfig::LEGACY_CARD_DIRECTORY] as $directory) {
            $files = glob($rootPath . '/' . $directory . '/*.md');
            if ($files === false || $files === []) {
                continue;
            }

            sort($files);
            foreach ($files as $file) {
                if (preg_match('/^([A-Za-z][A-Za-z0-9]*)-[0-9]+\.md$/', basename($file), $matches) === 1) {
                    return strtoupper($matches[1]);
                }
            }
        }

        return null;
    }
}
