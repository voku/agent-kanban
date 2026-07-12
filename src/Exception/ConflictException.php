<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * An optimistic-concurrency or claim conflict was detected: the card on disk
 * no longer matches the revision (or claim) the caller expected.
 */
final class ConflictException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly string $cardId,
        public readonly ?string $expectedRevision = null,
        public readonly ?string $actualRevision = null,
    ) {
        parent::__construct($message);
    }
}
