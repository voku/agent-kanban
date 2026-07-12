# External issue synchronization (optional)

`agent-kanban` can compare local cards against an external tracker (Jira or
anything else), but the core package:

- has **no network dependency**,
- stores **no credentials**,
- and contains **no Jira-specific (or any other tracker-specific) status
  strings**.

All of that lives in a host-provided adapter implementing
`ExternalIssueProvider`.

## The contract

```php
namespace voku\AgentKanban\ExternalIssue;

interface ExternalIssueProvider
{
    public function systemName(): string;

    /** @return list<ExternalIssueRecord> */
    public function fetchActiveIssues(string $query): array;
}
```

`ExternalIssueRecord` is `{ key, summary, status, updatedAt }` — already
normalized to this package's shape; the provider does the translation from
whatever the tracker's API actually returns.

`ExternalIssueComparator::compare(CardCollection $cards, list<ExternalIssueRecord> $remoteIssues, BoardConfig $config, ?string $system = null): ExternalIssueDrift`
matches a card to a remote record either by an explicit
`- **External issue:** <system>:<key>` field, or — the common case for
boards where the local ticket ID *is* the tracker key — by `Card::$id`
directly. Pass the syncing provider's `systemName()` as `$system` (the CLI's
`external-sync` command always does) so a board that mixes trackers never
compares a card explicitly pointed at a *different* tracker against this
one's remote records; cards with no explicit reference still match by ID
regardless of `$system`.

## Drift categories

`ExternalIssueDrift::entries` is a flat `list<ExternalIssueDriftEntry>`, each
tagged with a `DriftKind`:

| `DriftKind` | Meaning |
| --- | --- |
| `missing-locally` | The remote query returned an issue with no matching local card. |
| `no-longer-active-remotely` | A local card references a key the remote query no longer returns as active. |
| `status-drift` | Card status differs from the remote status. |
| `lane-drift` | The remote status maps (via `BoardConfig::$statusToLane`, when unambiguous) to a different lane than the card is currently in. |
| `summary-drift` | Card summary differs from the remote summary. |
| `update-time-drift` | Both sides have an updated timestamp and they differ. |

## Example: a Jira adapter (not shipped, write this in your own project)

```php
namespace YourApp;

use voku\AgentKanban\ExternalIssue\{ExternalIssueProvider, ExternalIssueRecord};

final class JiraExternalIssueProvider implements ExternalIssueProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken, // read from your own secret store, never from agent-kanban
        private readonly string $projectKey,
    ) {
    }

    public function systemName(): string
    {
        return 'jira';
    }

    public function fetchActiveIssues(string $query): array
    {
        // $query is whatever your CLI invocation passed via --query
        // (e.g. a JQL string); this package never constructs or validates it.
        $response = /* call the Jira REST API with $this->apiToken */;

        return array_map(
            static fn (array $issue): ExternalIssueRecord => new ExternalIssueRecord(
                key: $issue['key'],
                summary: $issue['fields']['summary'],
                status: $issue['fields']['status']['name'],
                updatedAt: new \DateTimeImmutable($issue['fields']['updated']),
            ),
            $response['issues'],
        );
    }
}
```

Run it via the CLI:

```bash
vendor/bin/agent-kanban external-sync \
  --provider-class="YourApp\\JiraExternalIssueProvider" \
  --query="project = ITPNG AND statusCategory != Done ORDER BY updated DESC"
```

The CLI instantiates `--provider-class` with a no-argument constructor, so
your adapter should read its own configuration (base URL, token, project
key) from environment variables or your own config file inside its
constructor — never from `agent-kanban`.

## Why there's no adapter registry

A generic plugin/registry system for `ExternalIssueProvider` implementations
is deliberately not built until a second real provider exists. One real
implementation (a documented Jira example) doesn't justify the extra
indirection; see `docs/PLAN.md`'s decisions log.

## Contract tests

`ExternalIssueComparator` is exercised in
`tests/ExternalIssue/ExternalIssueComparatorTest.php` against plain
`ExternalIssueRecord` fixtures (no real provider needed) — use that file as
the executable contract for what "drift" means before writing your own
provider's tests.
