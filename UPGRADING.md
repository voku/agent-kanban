# Upgrading

## From 0.1.x

Nothing is required to keep existing boards working. `todo/cards/` and
`todo/jira/` are both still read exactly as before, existing card files
(bullet-metadata Markdown, including the 0.x-only `Fit` field and legacy
`Next pull rank` field) parse unchanged, and `TodoBoardSource` /
`TodoBoardVerifier` / `TodoBoardCli` still exist with their original method
signatures. No card file is ever rewritten as a side effect of reading it.

That said, several things did change under the hood, and the CLI binary now
runs a different implementation. Read on for what to check.

### The CLI binary changed implementation

`vendor/bin/agent-kanban` now runs `voku\AgentKanban\Cli\CliApplication`
instead of the deprecated `TodoBoardCli`. If you only ever ran the binary
(not the PHP class directly), check the table below for command-name and
output changes.

| 0.x | 1.0 | Notes |
| --- | --- | --- |
| `summary` | `summary` | Same command; output format is generic now (no project-specific policy prose). |
| `render [filters]` | `render [filters]` | Filter flags are the same (`--lanes`, `--domain`, `--assignee`, `--status`, `--search`, `--limit`); output is a generic Markdown render, not the old giant board document. |
| `lane <LANE>` | `lane <LANE>` | Same; now also validates the lane is one of the board's configured lanes and errors clearly if not. |
| `next-pull` | `next-pull` | Same semantics: priority `> 0`, ascending. |
| `ticket <ID>` / `context <ID>` | `card show <ID>` | Old names still work as aliases. |
| `brief <ID>` | `brief <ID>` (alias) or `card show <ID>` | Old name still works. |
| `jira-sync [--jql=...]` | `external-sync --provider-class=<FQCN> [--query=...]` | `jira-sync` still works as an alias and accepts `--jql` as a synonym for `--query`, **but** you must now pass `--provider-class` pointing at your own `ExternalIssueProvider` implementation — the CLI no longer accepts a `JiraIssueProvider` constructor argument directly. See `docs/external-issues.md`. |
| *(none)* | `verify`, `card create/update/move/claim/release/archive/restore` | New. See `docs/cli.md`. |

If you were constructing `TodoBoardCli` yourself in PHP (not via the
binary) and passing a `JiraIssueProvider`, that constructor argument still
works — `TodoBoardCli` is deprecated but functional, and adapts your
provider internally.

### `TodoBoardVerifier` checks changed from hard-coded to configurable

The old `TodoBoardVerifier` checked a fixed set of rules baked into the
engine: exact German Jira status names per lane, a WIP limit of `3`,
required Markdown section headings matching one specific rendered template.
The deprecated `TodoBoardVerifier` facade now runs the generic
`BoardVerifier` instead, which:

- Has **no** hard-coded status vocabulary (configure
  `BoardConfig::$statusToLane` if you want lane/status restrictions — see
  `docs/configuration.md`).
- Has **no** hard-coded WIP limit (configure `BoardConfig::$wipLimits`).
- No longer requires the old rendered-document's specific section headings
  to exist anywhere (`### WIP Health`, `### Board Snapshot`, etc.) — those
  were an artifact of the old "reparse the generated board" architecture,
  which no longer exists.
- Still checks: duplicate card IDs, invalid filenames/prefixes, unsupported
  lanes, missing task briefs for `READY` (the one default it keeps, since it
  matches the original workflow), malformed card metadata, and the
  entrypoint-consistency check (`TODO.md` referencing the active card
  directory) that the original `testVerifierFailsWhenIndexStillPointsAtJiraButCardsLiveUnderPreferredDirectory`
  test covers.
- Reports its pass/fail via the same `run(): int` contract
  (`"TODO board verification passed."` / `"TODO board verification
  failed: <first error message>"`, exit `0`/`1`) as before.

If your project relied on the *specific* old hard-coded rules (exact status
strings, WIP limit of 3, exact section headings), reproduce them as your own
`BoardConfig` (see `docs/configuration.md`) and call `BoardVerifier`
directly, rather than the deprecated facade.

### New card fields are optional, additive

`Created`, `Priority` (with `Next pull rank` still read as a fallback),
`Claim`, `External issue`, and `Format version` are all new, optional bullet
fields. Existing cards without them parse exactly as before (defaults:
`createdAt` null, `priority` null, `claim` null, `externalIssue` null,
`formatVersion` 1). See `docs/card-format.md`.

### Timestamps are canonicalized on write, never on read

Reading a card with the legacy `dd.mm.YYYY[ HH:MM:SS]` timestamp format
still works and never rewrites the file. If you (or a coding agent) later
*update* that same card through `CardMutationService` / the CLI, the
timestamp fields you touch are rewritten in canonical ISO-8601
(`Y-m-d\TH:i:sP`) as part of that explicit write — this is the serializer's
normal, deterministic output format, not a silent bulk migration. Running
`agent-kanban verify` or any read-only command never writes anything.

### Recommended follow-up (optional, not required)

- Add a `todo/kanban.config.json` (or `- **Project prefix:**` in
  `todo/board.md`) instead of relying on prefix inference from existing
  filenames, especially for a brand-new board with no cards yet.
- If you use Jira sync, write a small `ExternalIssueProvider` adapter (see
  `docs/external-issues.md`) and switch your automation to
  `external-sync --provider-class=...`.
- If you called any of the deprecated `TodoBoard*` classes directly from
  PHP, consider migrating to the typed API (`docs/php-api.md`) — the
  deprecated classes will eventually be removed in a future major version,
  with advance notice in `CHANGELOG.md`.

## Format migrations

There is no format migration in this release: the on-disk card format is
unchanged (still bullet-metadata Markdown, not YAML frontmatter — see
`docs/card-format.md`'s rationale). If a future release ever changes the
on-disk format, it will be explicit, reviewable, and opt-in — never a
silent rewrite triggered by reading an old file.
