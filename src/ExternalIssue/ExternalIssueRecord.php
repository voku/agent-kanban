<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

use DateTimeImmutable;

/**
 * A normalized issue record fetched from an external tracker. Providers
 * (e.g. a Jira adapter, see `docs/external-issues.md`) translate whatever
 * shape their API returns into this; the comparator never sees the
 * tracker-native shape.
 */
final readonly class ExternalIssueRecord
{
    public function __construct(
        public string $key,
        public string $summary,
        public string $status,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }
}
