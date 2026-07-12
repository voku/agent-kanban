<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

use voku\AgentKanban\Repository\BoardMetadata;

/**
 * Optional board-wide, filesystem-derived facts that {@see BoardVerifier}
 * cannot know from a {@see \voku\AgentKanban\Board} alone (a Board only
 * knows the cards that were actually loaded from the *resolved* card
 * directory). Supplying this context enables the checks that need it
 * (archive conflicts, source-directory ambiguity, board-metadata
 * consistency); omitting it simply skips them.
 */
final readonly class BoardVerificationContext
{
    /**
     * @param list<string> $archivedCardIds Card IDs currently present in the archive directory.
     */
    public function __construct(
        public array $archivedCardIds = [],
        public bool $bothCardDirectoriesExist = false,
        public ?BoardMetadata $boardMetadata = null,
        public ?string $indexContent = null,
        public ?string $cardDirectory = null,
    ) {
    }
}
