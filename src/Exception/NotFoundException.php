<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * A requested card (or file) does not exist.
 */
final class NotFoundException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly ?string $cardId = null,
    ) {
        parent::__construct($message);
    }
}
