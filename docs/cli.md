# CLI reference

```bash
vendor/bin/agent-kanban <command> [options]
```

Implemented by `voku\AgentKanban\Cli\CliApplication`, which delegates every
command to the typed engine (`BoardQueryService`, `BoardRenderer`,
`BoardVerifier`, `CardMutationService`, `ExternalIssueComparator`) — the CLI
class itself contains no board logic.

## Commands

| Command | Description |
| --- | --- |
| `help`, `--help`, `-h` | Print usage and exit `0`. |
| `summary` | Lane counts and WIP health. |
| `render [filters]` | Render lanes with optional filters (see below). |
| `verify` | Verify board integrity; see exit codes below. |
| `next-pull` | Cards with a configured pull priority `> 0`, ranked ascending. |
| `lane <LANE>` | Cards in one lane. |
| `card show <ID>` | Show one card. |
| `card create <ID> --title=... [--lane=] [--status=] [--summary=]` | Create a new card (defaults: lane `BACKLOG`, empty status). |
| `card update <ID> [--title=] [--status=] [--domain=] [--assignee=] [--summary=] [--next=] [--validation=] [--priority=] [--wave=] [--brief=] [--handoff=]` | Update only the fields you pass. |
| `card move <ID> --to=<LANE> [--actor=]` | Move a card; validated against `BoardConfig::$transitions`. |
| `card claim <ID> --by=<actor> [--expires=<ISO8601>] [--move-to-doing]` | Claim a card. |
| `card release <ID> --by=<actor>` | Release your own claim. |
| `card archive <ID>` | Move a card into `archiveDirectory` (must be configured). |
| `card restore <ID>` | Move a card back out of the archive. |
| `external-sync --provider-class=<FQCN> [--query=...]` | Compare local cards against an `ExternalIssueProvider` (see `docs/external-issues.md`). |

## Render filters

```text
--lanes=A,B   --domain=<substr>   --assignee=<substr>
--status=<substr>   --search=<substr>   --limit=<N>
```

`--search` matches (case-insensitively) against card ID, status, domain,
assignee, and summary. `--limit` caps how many cards are shown per lane
(`0` or omitted = no limit).

## Global options

| Option | Effect |
| --- | --- |
| `--format=text\|markdown\|json` | Output format. Default `text`. `markdown` and `text` currently render the same Markdown output; `json` is versioned (see `docs/json-format.md`). |
| `--dry-run` | For any `card` mutation command: validate and compute the result, but never write. |
| `--expected-revision=<sha256>` | Optimistic-concurrency check; the command fails with a conflict if the card's current revision does not match. |
| `--root=<path>` | Board root directory. Default: current working directory. |
| `--config=<path>` | Explicit `BoardConfig` JSON file. See `docs/configuration.md` for the default resolution order when omitted. |

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | Usage or validation error (bad arguments, disallowed transition, malformed input). |
| `2` | Requested card (or archived card) not found. |
| `3` | Conflict: stale `--expected-revision`, or a claim held by someone else. |
| `4` | `verify` found at least one error-level violation. |
| `5` | Configuration error (e.g. project prefix could not be resolved, missing `archiveDirectory`). |
| `6` | An `ExternalIssueProvider` threw while fetching remote issues. |

`help`, `--help`, and `-h` always exit `0`. Data output always goes to
STDOUT; errors, warnings, and the `verify` pass/fail line go to STDERR (for
`verify`, per-violation lines go to STDERR for errors and STDOUT for
warnings, so `--format=text` output stays scriptable — grep STDOUT for
warnings, STDERR for anything that fails the build).

## Examples

```bash
vendor/bin/agent-kanban verify --format=json
vendor/bin/agent-kanban card claim ITPNG-123 --by=codex --move-to-doing
vendor/bin/agent-kanban card update ITPNG-123 --summary="Narrower scope" --dry-run
vendor/bin/agent-kanban card move ITPNG-123 --to=VERIFY --expected-revision=3f2504e...
vendor/bin/agent-kanban render --lanes=READY,DOING --search=security --format=json
```
