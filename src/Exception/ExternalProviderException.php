<?php

declare(strict_types=1);

namespace voku\AgentKanban\Exception;

/**
 * An ExternalIssueProvider failed. Messages must never leak credentials; the
 * package never stores or logs provider credentials itself.
 */
final class ExternalProviderException extends AgentKanbanException
{
    public function __construct(
        string $message,
        public readonly string $providerName,
    ) {
        parent::__construct($message);
    }
}
