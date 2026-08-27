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
| `card create <ID> --title=... [--lane=] [--status=] [--summary=] [--next=] [--validation=] [--brief=]` | Create a new card (defaults: lane `BACKLOG`, empty status, next action, validation, and task brief). Use `--brief` when creating directly into a lane that requires `taskBrief`. |
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

## Keeping JSON output small

A full `card` object carries every field a card file can hold, including the
unbounded `taskBrief` and `handoffNotes` prose and the 64-character `revision`
digest. When the reader is a language model rather than a human — the
`voku/agent-loop` case — that is the dominant cost of every board listing, and
most of it is never read.

Two options reduce it. Both apply to the commands that emit card objects
(`render`, `lane`, `next-pull`, `card show`) and both require `--format=json`;
`--compact` additionally applies to every other JSON shape, including
`summary`, `verify`, mutation results, and errors.

| Option | Effect |
| --- | --- |
| `--fields=a,b,c` | Emit only these card fields. |
| `--compact` | Emit the JSON without pretty-print indentation and line breaks. The single trailing newline after the document is kept. |

```bash
vendor/bin/agent-kanban next-pull --format=json --fields=lane,status,priority --compact
```

```json
{"schemaVersion":1,"type":"card-list","generatedAt":"2026-07-12T09:00:00+00:00","count":2,"cards":[{"id":"ITPNG-123","lane":"READY","status":"Selected","priority":1},{"id":"ITPNG-124","lane":"READY","status":"Selected","priority":2}]}
```

Guarantees that make the reduced output safe to parse with the same code as
the full output:

- the envelope is unchanged — `schemaVersion`, `type`, `generatedAt`, and
  `count` are always present, and `schemaVersion` does **not** change, because
  the shape is a documented subset rather than a new shape;
- `id` is always emitted, whether or not you name it — a card object that
  cannot be tied back to a card is not a cheaper answer;
- fields come back in the canonical order from `docs/json-format.md`, not in
  the order you requested them;
- `--compact` changes only insignificant whitespace, never the data or the
  escaping.

Valid field names are exactly the keys of the `card` object:

```text
id  title  lane  status  domain  assignee  createdAt  updatedAt  summary
nextAction  validation  priority  wave  taskBrief  handoffNotes  claim
externalIssue  formatVersion  extensionFields  revision  sourceFile
```

Field selection is strict in the same way the rest of the option parsing is:
an unknown name (`--fields=brief`), a repeated name (`--fields=id,id`), an
empty entry (`--fields=id,,lane`), or a wrong case (`--fields=Lane`) is
rejected with exit code `1` rather than silently dropped — a silently ignored
field name would hand back a card object missing data you believe you asked
for. Using either option without `--format=json` is likewise rejected instead
of ignored.

## Option parsing is strict

Value-taking long options accept both `--name=value` and `--name value`;
examples use the equals form where it keeps command shapes compact.

`ArgvParser` rejects, rather than silently ignoring or defaulting:

- an option name it doesn't recognize at all (`--bogus=x`);
- an option supplied more than once (`--limit=1 --limit=2`);
- a non-boolean option given without a value (`--limit`);
- a value given to a boolean flag (`--dry-run=true`);
- a non-integer value where an integer is required (`--limit=banana`,
  `--priority=banana`).

On top of that, each command only accepts the options that are actually
meaningful for it — `--root`, `--config`, and `--format` are the only
options every command accepts unconditionally, joined by `--compact` whenever
`--format=json` is in play; anything else is validated against a
per-command allow-list (e.g. `summary --actor=someone` and
`verify --title=Something` are both rejected, not silently ignored, even
though `--actor` and `--title` are valid options for other commands). Every
rejection is a `ValidationException` (exit code `1`), never a raw stack
trace.

## Global options

| Option | Effect |
| --- | --- |
| `--format=text\|markdown\|json` | Output format. Default `text`. `markdown` and `text` currently render the same Markdown output; `json` is versioned (see `docs/json-format.md`). |
| `--compact` | Drop pretty-print whitespace from JSON output. Requires `--format=json`. See "Keeping JSON output small". |
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
STDOUT; errors and the `verify` pass/fail line go to STDERR. Warnings
generally go to STDERR too (e.g. `card claim --move-to-doing`'s warning
when the move itself couldn't happen), **except** `verify`'s per-violation
output: error-severity violations go to STDERR, but warning-severity
violations are the one case that goes to STDOUT instead, so
`--format=text verify` output stays scriptable — grep STDOUT for warnings,
STDERR for anything that fails the build.

## Examples

```bash
vendor/bin/agent-kanban verify --format=json
vendor/bin/agent-kanban card create ITPNG-123 --title="Ready task" --lane=READY --status=Selected --next="Implement one verified behavior." --validation="vendor/bin/phpunit" --brief="Implement one verified behavior."
vendor/bin/agent-kanban card claim ITPNG-123 --by=codex --move-to-doing
vendor/bin/agent-kanban card update ITPNG-123 --summary="Narrower scope" --dry-run
vendor/bin/agent-kanban card move ITPNG-123 --to=VERIFY --expected-revision=3f2504e...
vendor/bin/agent-kanban render --lanes=READY,DOING --search=security --format=json
vendor/bin/agent-kanban next-pull --format=json --fields=lane,status,priority --compact
```