<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use voku\AgentKanban\Cli\CliApplication;

/**
 * @deprecated Use Verification\BoardVerifier and Verification\VerificationReport.
 */
final class TodoBoardVerifier
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $projectPrefix = null,
    ) {
    }

    public function run(): int
    {
        $argv = ['agent-kanban', 'verify', '--root=' . $this->rootPath];
        if ($this->projectPrefix !== null) {
            $configPath = $this->rootPath . '/todo/kanban.compat.json';
            if (!is_file($configPath)) {
                $directory = dirname($configPath);
                if (!is_dir($directory)) {
                    mkdir($directory, 0o777, true);
                }
                file_put_contents($configPath, json_encode(['projectPrefix' => $this->projectPrefix], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            }
            $argv[] = '--config=' . $configPath;
        }

        return (new CliApplication($this->rootPath))->run($argv);
    }
}
