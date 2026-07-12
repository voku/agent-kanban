<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * A filesystem operation (read, write, lock, rename) failed. Never thrown for
 * validation or conflict conditions, only for genuine I/O failure.
 */
final class IoException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message);
    }
}
