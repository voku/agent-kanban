# Changelog

All notable changes to this project will be documented in this file.

## Unreleased — typed engine, safe mutations, JSON output, CLI rewrite

This is a large, mostly-backward-compatible architectural rework building
toward a stable 1.0 API. See `docs/PLAN.md` for the full rationale and
`UPGRADING.md` for migration instructions. **Not tagged or released** — this
is in-progress work on the way to a 1.0.0 the maintainer will cut separately,
after real-world validation.

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

### Changed

- `TodoBoardSource`, `TodoBoardVerifier`, and `TodoBoardCli` are deprecated
  (`@deprecated` docblocks) and now delegate internally to the new engine
  instead of generating and re-parsing a large project-specific Markdown
  document. Their *outward* method contracts (`readBoardMarkdown()`,
  `resolveCardDirectory()`, `run()` pass/fail message + exit code, etc.) are
  unchanged and covered by the original 0.1.0 test suite, which still passes
  unmodified against these classes. Their *internal* validation rules are
  now generic and configurable instead of hard-coded (see below).
- `bin/agent-kanban` now runs `Cli\CliApplication` instead of the deprecated
  `TodoBoardCli`. See `UPGRADING.md` for the command mapping; several 0.x
  command names (`ticket`, `context`, `brief`, `jira-sync`) are still
  accepted as aliases.
- `TodoBoardVerifier` no longer hard-codes German Jira status names
  (`Selected`, `In Planung`, `Warten`, `Fertig`), a WIP limit of `3`, or
  required Markdown section headings from the old rendered-board template.
  It now runs the generic `BoardVerifier` against the typed board model.

### Removed

- The generated-Markdown-as-internal-database pattern: nothing in this
  package parses its own rendered output anymore.
- Hard-coded project-specific policy (the literal `ITPNG` prefix as a
  silent fallback, German Jira status vocabulary, a fixed WIP limit,
  `MEMORY.md` / `make memory_review` / Docker-validation references baked
  into rendered board text) is gone from the engine. Equivalent behavior is
  available as host configuration (`docs/configuration.md`) or host
  documentation, never as an engine invariant.

## 0.1.0 - 2026-06-22

- add support for preferred card directory (`todo/cards`) over legacy
  (`todo/jira`)
