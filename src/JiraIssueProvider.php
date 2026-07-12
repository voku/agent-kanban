<?php

declare(strict_types=1);

namespace voku\AgentKanban;

/**
 * @deprecated Implement \voku\AgentKanban\ExternalIssue\ExternalIssueProvider instead.
 */
interface JiraIssueProvider
{
    public function projectKey(): string;

    /**
     * @return list<array{key: string, summary: string, status: string, updated_at: string}>
     */
    public function searchIssues(string $jql): array;
}
