<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use voku\AgentKanban\Exception\ValidationException;

/**
 * An optional pointer from a card to an issue in an external tracker, e.g.
 * `ExternalIssueRef::of('jira', 'ITPNG-123')`.
 *
 * The generic board engine never interprets `$system`; it exists purely so
 * an {@see \voku\AgentKanban\ExternalIssue\ExternalIssueProvider} can match a
 * local card back to a remote record.
 */
final readonly class ExternalIssueRef
{
    public function __construct(
        public string $system,
        public string $key,
    ) {
        if (trim($system) === '' || trim($key) === '') {
            throw new ValidationException('External issue reference requires both a system and a key.', field: 'external_issue');
        }
    }

    public function toString(): string
    {
        return $this->system . ':' . $this->key;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
