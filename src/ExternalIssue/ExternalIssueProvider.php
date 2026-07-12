<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

/**
 * A read-only source of normalized external-issue records, e.g. a Jira
 * adapter the host application provides. The core engine never implements a
 * concrete provider, never stores credentials, and has no network
 * dependency — see `docs/external-issues.md` for a documented Jira example
 * kept entirely outside this package.
 */
interface ExternalIssueProvider
{
    /**
     * A short, host-defined identifier for the tracker this provider talks
     * to (e.g. `"jira"`). Never interpreted by the generic engine.
     */
    public function systemName(): string;

    /**
     * Fetches the currently "active" issues for the given host-defined
     * query (e.g. a JQL string). What "active" means is entirely up to the
     * host and the provider implementation.
     *
     * @return list<ExternalIssueRecord>
     */
    public function fetchActiveIssues(string $query): array;
}
