<?php

declare(strict_types=1);

namespace voku\AgentKanban;

use voku\AgentKanban\Exception\AgentKanbanException;
use voku\AgentKanban\Legacy\LegacyBoardContextResolver;
use voku\AgentKanban\Verification\BoardVerificationContext;
use voku\AgentKanban\Verification\BoardVerifier;

/**
 * @deprecated since 0.2.0. Previously re-parsed a large generated Markdown
 *             document with hard-coded, project-specific rules (German Jira
 *             status vocabulary, a fixed WIP limit of 3, required section
 *             headings). It now delegates to the typed
 *             {@see BoardVerifier}, which checks the same class of problems
 *             (lane/status validity, WIP limits, required fields, board
 *             entrypoint consistency) generically and configurably instead
 *             of against one hard-coded template. The pass/fail message and
 *             exit-code contract of `run()` is unchanged. Use
 *             {@see BoardVerifier} directly for structured violations. See
 *             UPGRADING.md.
 */
final class TodoBoardVerifier
{
    private ?string $projectPrefix;

    public function __construct(
        private readonly string $rootPath,
        ?string $projectPrefix = null,
    ) {
        $this->projectPrefix = $projectPrefix;
    }

    public function run(): int
    {
        try {
            $context = LegacyBoardContextResolver::resolve($this->rootPath, $this->projectPrefix);
            $lenient = $context->repository->loadAllLenient();
            $ownCards = $lenient->cards->filter(
                static fn ($card): bool => $card->id->prefix === $context->config->projectPrefix,
            );

            $indexPath = $this->rootPath . '/TODO.md';
            $indexContent = is_file($indexPath) ? file_get_contents($indexPath) : false;

            $verificationContext = new BoardVerificationContext(
                indexContent: $indexContent === false ? null : $indexContent,
                cardDirectory: $context->cardDirectory,
            );

            $board = new Board($context->config, $ownCards, $context->cardDirectory, $context->metadata->doneCount);
            $report = (new BoardVerifier())->verify($board, $lenient->failures, $verificationContext);

            if ($report->isValid()) {
                echo "TODO board verification passed.\n";

                return 0;
            }

            $first = $report->errors()[0];
            fwrite(\STDERR, "TODO board verification failed: {$first->message}\n");

            return 1;
        } catch (AgentKanbanException $exception) {
            fwrite(\STDERR, "TODO board verification failed: {$exception->getMessage()}\n");

            return 1;
        }
    }
}
