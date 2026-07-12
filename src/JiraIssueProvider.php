<?php

declare(strict_types=1);

namespace voku\AgentKanban;

/**
 * @deprecated since 0.2.0, kept for the deprecated {@see TodoBoardCli}. Use
 *             {@see \voku\AgentKanban\ExternalIssue\ExternalIssueProvider}
 *             instead, which is not Jira-specific. See UPGRADING.md and
 *             docs/external-issues.md.
 */
interface JiraIssueProvider
{
    public function projectKey(): string;

    /**
     * @return list<array{
     *     key: string,
     *     summary: string,
     *     status: string,
     *     updated_at: string
     * }>
     */
    public function searchIssues(string $jql): array;
}
