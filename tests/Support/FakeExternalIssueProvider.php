<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Support;

use voku\AgentKanban\ExternalIssue\ExternalIssueProvider;
use voku\AgentKanban\ExternalIssue\ExternalIssueRecord;

/**
 * A minimal, real ExternalIssueProvider used by CLI external-sync tests.
 */
final class FakeExternalIssueProvider implements ExternalIssueProvider
{
    public function systemName(): string
    {
        return 'fake';
    }

    /**
     * @return list<ExternalIssueRecord>
     */
    public function fetchActiveIssues(string $query): array
    {
        return [];
    }
}
