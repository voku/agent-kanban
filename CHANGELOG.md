# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Added

- `BoardConfig` & `BoardContextResolver`: Added multi-board support. `todo/kanban.config.json` can now configure multiple boards under a `"boards"` array and specify a `"defaultBoard"`. `BoardContextResolver::resolveAll()` resolves all configured boards, and `BoardContextResolver::resolve()` supports resolving a specific board by board ID or project prefix.

## 0.3.2 - 2026-08-17

### Fixed

- Value-taking long CLI options now accept both `--name=value` and
  `--name value`, so delegated callers such as `agent-loop board` use the
  same option grammar as the umbrella workflow commands. Strict validation
  for unknown, duplicate, boolean, empty and missing option values remains
  unchanged.
- CLI help and validation messages now describe that two-form contract
  without implying that the equals form is mandatory.

## 0.3.1 - 2026-08-16

### Added

- `card create` accepts `--brief=<text>`, so a card can be created atomically
  with the task brief required by its target lane.

### Fixed

- Card mutations now reuse the board verifier before persistence and reject
  candidate state that would immediately make the board verifier-invalid.
  This closes the split-brain where a mutation could report success and the
  next `verify` would reject the written card.
- READY cards can therefore be created in one write when all required fields
  are supplied, while create/move/claim/release/restore cannot silently
  introduce card-local verifier errors.

## 0.3.0 - 2026-08-15

### Changed

- **Breaking:** the standalone CLI default workspace moves from `<cwd>` to
  `<cwd>/.agent-loop`, so the default board directory moves from `todo/` to
  `.agent-loop/todo/`. There is deliberately no hidden fallback or dual-read;
  callers that keep a custom/legacy location must pass `--root` explicitly.
  The typed PHP APIs remain explicit about their root and therefore do not
  inherit this CLI-only migration. See `UPGRADING.md`.
- Release tags can now be requested deterministically through
  `.release/<version>.json`, bound to an exact candidate SHA. This matches the
  release mechanism used by the sibling agent packages instead of relying on
  an unrecorded manual tag step.

### Added

- `--fields=<a,b,c>` for the commands that emit card objects (`render`,
  `lane`, `next-pull`, `card show`): emit only the named card fields instead
  of the complete card object. Cuts the dominant cost of feeding board JSON to
  a coding agent, which is the unbounded `taskBrief`/`handoffNotes` prose and
  the 64-character `revision` digest repeated for every card.
- `--compact` (any command, `--format=json` only): emit JSON without
  pretty-print indentation and newlines.
- `Rendering\CardFieldSelection`, and optional `CardFieldSelection`/`compact`
  arguments on `JsonBoardRenderer::cardToArray()`, `cardToEnvelope()`,
  `cardsToEnvelope()` and `encode()`, so the same reduction is available to
  PHP callers.
- `Cli\OutputOptions`, the typed value object carrying a command's format,
  compact flag and field selection.

Both options are additive. `schemaVersion` is unchanged: a reduced card object
is a documented subset of the existing `card` shape — same envelope, same
canonical key order — not a new shape. Output for callers that pass neither
option is byte-for-byte unchanged. `id` is always emitted, whether or not it
was named. Both options are rejected with exit code `1` when used without
`--format=json`, as is an unknown, repeated or empty field name, rather than
being silently ignored.

See `docs/cli.md` ("Keeping JSON output small"), `docs/json-format.md`
("Reduced card objects") and `docs/agent-loop-integration.md` ("Reading a
board without spending the context window").

### Fixed

- A malformed option token (`--fields`, `--fields=`, `--limit`, `--bogus=1`,
  `--compact=yes`, a repeated option, ...) escaped the CLI as an uncaught
  exception: a raw stack trace on STDERR and exit code `255`, instead of the
  documented `ValidationException` behavior of a clean message and exit code
  `1`. `ArgvParser::parse()` ran outside `CliApplication::run()`'s error
  boundary; it now runs inside it, and `--format=json` is honored for these
  errors too, so a JSON-only consumer gets a JSON `error` document rather than
  a stack trace. Present since 0.2.0 and not specific to the new options.

## 0.2.1 - 2026-07-13

- Handle additional case for empty priority in CardParser

## 0.2.0 — 2026-07-12

... typed engine, safe mutations, JSON output, CLI rewrite

This is a large architectural rework building toward a stable 1.0 API,
**including breaking changes**: the pre-1.0 `TodoBoardSource`/
`TodoBoardVerifier`/`TodoBoardCli` classes and the CLI commands built on them
are removed outright rather than kept as deprecated facades, since this
project has one known consumer (`voku/agent-loop`) and a clean break was
judged better than carrying the old generated-Markdown architecture forward.
See `docs/PLAN.md` for the full rationale and `UPGRADING.md` for a
class-by-class and command-by-command migration guide.

The on-disk board format is unchanged and fully backward compatible — see
"Compatibility" below.

### Added

- A typed domain model (`Domain\Card`, `CardId`, `Lane`, `CardStatus`,
  `CardRevision`, `Claim`, `ExternalIssueRef`, `CardCollection`) parsed
  directly from card files — no intermediate generated Markdown.
- `Config\BoardConfig`: project prefix, lanes, status-to-lane mapping, WIP
  limits, required fields per lane, transitions, format version, archive
  directory, external-issue system name. Nothing project-specific is
  hard-coded in the engine anymore.
- `Repository\CardParser` / `CardSerializer`: a formally specified,
  deterministic card format (`docs/card-format.md`) with stable field order,
  newline normalization, and documented invalid-input behavior. Unknown
  bullet fields (e.g. the legacy `Fit` field) round-trip losslessly as
  extension fields.
- `Repository\MarkdownCardRepository`: strict (`loadAll()`) and lenient
  (`loadAllLenient()`) loading, atomic writes (`atomicWrite()`), atomic
  moves (`moveFile()`, used by archive/restore), symlink-safe.
- `Query\BoardQueryService`: typed board queries (summary, by lane/status/
  assignee/domain, search, next-pull candidates, blocked cards, WIP health)
  over parsed cards — never over rendered Markdown.
- `Rendering\BoardRenderer` (Markdown, generic — no hard-coded project
  policy prose) and `Rendering\JsonBoardRenderer` (versioned JSON; see
  `docs/json-format.md`).
- `Verification\BoardVerifier`: structured `VerificationReport` of
  `Violation`s with a stable `ViolationCode`, `Severity`, and card/field/file
  context. Never writes to STDOUT/STDERR. Covers duplicate card IDs, invalid
  filenames/prefixes, unsupported lanes, invalid status-to-lane mappings,
  missing required fields/task briefs, invalid timestamps, malformed/
  duplicate metadata, invalid WIP counts, invalid claims, invalid transition
  states, board-metadata inconsistencies, stale/incompatible format
  versions, archive conflicts, and source-directory ambiguity.
- `Transition\TransitionPolicy` / `TransitionResult`: configurable,
  validated lane-to-lane moves, decoupled from file writing.
- `Mutation\CardMutationService` / `MutationResult`: atomic, conflict-aware
  `create`/`update`/`move`/`claim`/`release`/`archive`/`restore`, all
  supporting `dryRun` and an optional `expectedRevision` (SHA-256-based
  optimistic concurrency). The original file is preserved on any failure.
- A small, deliberately non-distributed claim model (`Domain\Claim`): a
  current non-expired claim can't be silently replaced; expired claims can.
- `ExternalIssue\ExternalIssueProvider` / `ExternalIssueComparator`: a
  generic, credential-free, network-free contract for comparing local cards
  against an external tracker, replacing the Jira-specific logic previously
  built into the CLI. See `docs/external-issues.md`.
- A rewritten CLI (`Cli\CliApplication`) that delegates to the above:
  `help`, `summary`, `render`, `verify`, `next-pull`, `lane`,
  `card show/create/update/move/claim/release/archive/restore`,
  `external-sync`, with `--format=text|markdown|json`, `--dry-run`,
  `--expected-revision`, `--root`, `--config`, and documented, stable exit
  codes. See `docs/cli.md`.
- Full documentation set: `docs/architecture.md`, `card-format.md`,
  `configuration.md`, `cli.md`, `php-api.md`, `json-format.md`,
  `concurrency.md`, `external-issues.md`, `agent-loop-integration.md`,
  `troubleshooting.md`, plus `UPGRADING.md`, `CONTRIBUTING.md`,
  `SECURITY.md`.
- PHPStan at `max` level and php-cs-fixer, both passing on `src/` and
  `tests/`; `composer cs-check` / `cs-fix` scripts; CI matrix across PHP
  8.3/8.4/8.5 plus a clean-Composer-install verification job.
- A comprehensive test suite (unit, filesystem/concurrency integration, CLI
  subprocess, and compatibility tests) — see `docs/PLAN.md`'s VERIFY section
  for what was actually run and how.
- Hardened concurrency and path safety in `MarkdownCardRepository`: writes
  and moves take an exclusive per-card-file lock (`flock()`) and re-check
  the expected revision *while holding it*, so the file cannot change
  between that check and the write from another process using the
  repository API; lock files are removed after use without reintroducing
  the classic `flock()`-then-`unlink()` race (see `docs/concurrency.md`).
  Every path the repository touches is confined to the board root and
  checked component-by-component for symlinks, not just at the final
  segment. `BoardConfig` rejects an absolute, `..`-containing, or
  NUL-byte-containing configured directory outright (see
  `docs/configuration.md`).
- `Cli\ArgvParser` now rejects unknown options, duplicate options, a
  missing value on a non-boolean option, a value on a boolean flag, and a
  non-integer value where an integer is required, instead of silently
  falling back to a default. `CliApplication` additionally validates
  options against a per-command allow-list, so e.g. `summary --actor=x` or
  `verify --title=x` are rejected rather than silently ignored, even though
  `--actor`/`--title` are valid options for other commands. See
  `docs/cli.md`.

### Changed

- `bin/agent-kanban` now runs `Cli\CliApplication` instead of the removed
  `TodoBoardCli`. See `UPGRADING.md` for the full command mapping.

### Removed (breaking)

- **`TodoBoardSource`, `TodoBoardVerifier`, `TodoBoardCli`, `TodoBoardCard`,
  `TodoBoardRenderOptions`, and `JiraIssueProvider` are deleted**, not
  deprecated. Each has a direct typed-engine replacement documented in
  `UPGRADING.md` with a before/after code example. These classes generated
  and then re-parsed a large project-specific Markdown document — exactly
  the architecture pattern this release removes — and hard-coded German
  Jira status names, a fixed WIP limit of `3`, and required section
  headings from that one rendered template. None of that exists anywhere in
  the new engine; equivalent behavior is available as host `BoardConfig`
  (`docs/configuration.md`) or host documentation, never as an engine
  invariant.
- **CLI commands `ticket`, `context`, `brief`, and `jira-sync`** (and
  `jira-sync`'s `--jql` option) are removed rather than kept as aliases. Use
  `card show`, `card show` (includes the task brief), and
  `external-sync --provider-class=... --query=...` respectively — see
  `UPGRADING.md`.
- The generated-Markdown-as-internal-database pattern: nothing in this
  package parses its own rendered output anymore.

### Compatibility (unchanged)

- The on-disk card format is fully backward compatible: `todo/cards/`
  (preferred) and `todo/jira/` (legacy) are both still read, existing 0.x
  card files (including the legacy `Fit` field, `Next pull rank` field, and
  `dd.mm.YYYY` timestamp format) parse unchanged, and no card file is ever
  silently rewritten by reading it. Only the PHP classes and CLI commands
  built *around* that format changed.

## 0.1.0 - 2026-06-22

- add support for preferred card directory (`todo/cards`) over legacy
  (`todo/jira`)
