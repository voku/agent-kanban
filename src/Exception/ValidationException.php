<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * A card, config, or input value failed structural or semantic validation.
 */
final class ValidationException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly ?string $cardFile = null,
        public readonly ?string $field = null,
        public readonly ?string $cardId = null,
    ) {
        parent::__construct($message);
    }
}
