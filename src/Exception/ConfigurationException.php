<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * BoardConfig (or a config file) is missing, malformed, or internally
 * inconsistent (e.g. a status mapped to a lane that does not exist).
 */
final class ConfigurationException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly ?string $configPath = null,
    ) {
        parent::__construct($message);
    }
}
