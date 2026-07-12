<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * An optimistic-concurrency, destination, or claim conflict was detected.
 */
final class ConflictException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly ?string $cardId = null,
        public readonly ?string $expectedRevision = null,
        public readonly ?string $actualRevision = null,
    ) {
        parent::__construct($message);
    }
}
