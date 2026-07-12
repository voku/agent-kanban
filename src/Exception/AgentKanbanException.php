<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

use RuntimeException;

/**
 * Base type for every exception thrown by this package.
 *
 * Catch this to handle any agent-kanban failure without depending on the more
 * specific subtype; catch a subtype when you need to distinguish validation,
 * conflict, I/O, configuration, or external-provider failures from each other.
 */
abstract class AgentKanbanException extends RuntimeException
{
}
