<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Exception\IoException;

/**
 * Owns persistence of the conventional board configuration artifact.
 *
 * Embedding consumers may select the board root and provide a validated
 * {@see BoardConfig}; they do not need to know the owner's filename or create
 * board-private directories themselves.
 */
final readonly class BoardConfigurationWriter
{
    private const string CONVENTIONAL_CONFIG = 'todo/kanban.config.json';

    public function conventionalPath(string $rootPath): string
    {
        $root = $this->normalizedRoot($rootPath);

        return $root === '/'
            ? '/' . self::CONVENTIONAL_CONFIG
            : $root . '/' . self::CONVENTIONAL_CONFIG;
    }

    /**
     * Creates the conventional configuration only when it does not already
     * exist. Existing configuration remains authoritative and is never
     * overwritten by a bootstrap caller.
     */
    public function writeConventionalIfMissing(string $rootPath, BoardConfig $config): bool
    {
        $root = $this->normalizedRoot($rootPath);
        if (!is_dir($root)) {
            throw new IoException(
                sprintf('Board root does not exist or is not a directory: %s', $root),
                path: $root,
            );
        }

        $path = $this->conventionalPath($root);
        $directory = dirname($path);
        if (is_link($directory)) {
            throw new IoException(sprintf('Refusing to use symlinked board directory: %s', $directory), path: $directory);
        }
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new IoException(sprintf('Could not create board directory: %s', $directory), path: $directory);
        }

        if (is_link($path)) {
            throw new IoException(sprintf('Refusing to replace symlinked board configuration: %s', $path), path: $path);
        }
        if (is_file($path)) {
            return false;
        }
        if (file_exists($path)) {
            throw new IoException(sprintf('Board configuration path is not a regular file: %s', $path), path: $path);
        }

        $content = json_encode(
            $config->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        $handle = fopen($path, 'x');
        if ($handle === false) {
            clearstatcache(true, $path);
            if (is_file($path) && !is_link($path)) {
                return false;
            }

            throw new IoException(sprintf('Could not create board configuration: %s', $path), path: $path);
        }

        $complete = false;
        try {
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new IoException(sprintf('Could not write board configuration: %s', $path), path: $path);
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new IoException(sprintf('Could not flush board configuration: %s', $path), path: $path);
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete && is_file($path)) {
                unlink($path);
            }
        }

        return true;
    }

    private function normalizedRoot(string $rootPath): string
    {
        if ($rootPath === '/') {
            return '/';
        }

        $root = rtrim($rootPath, '/');
        if ($root === '') {
            throw new IoException('Board root path must not be empty.', path: $rootPath);
        }

        return $root;
    }
}
