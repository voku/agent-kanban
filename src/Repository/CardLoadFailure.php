<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

/**
 * One card file that failed to parse, captured for `agent-kanban verify`
 * instead of aborting the whole board load. See
 * {@see MarkdownCardRepository::loadAllLenient()}.
 */
final readonly class CardLoadFailure
{
    public function __construct(
        public string $file,
        public string $message,
        public ?string $cardId = null,
        public ?string $field = null,
    ) {
    }
}
