# Implementation plan: voku/agent-kanban 1.0

This is the repository-local implementation plan for taking `agent-kanban` from its
current `0.1.0` state to a stable, documented 1.0.0-ready API. It is maintained as a
living document: decisions and rejected alternatives stay visible even after the
work that motivated them is done, so future maintainers understand *why*, not just
*what*.

The goal is a **small, dependable library**: a typed PHP API plus a CLI for
Git-native, Markdown-based coding-agent Kanban boards. This is the foundation for a
UI and for `voku/agent-loop` to build on — not a platform.

## Product boundary (recap, see README for the full statement)

Owns: card parsing/serialization, board config, lanes/status, queries, rendering,
verification, validated transitions, safe mutations, board CLI, optional external
issue comparison.

Does not own: starting/controlling agents, LLM APIs, terminal streaming, UI,
WebSockets, Git worktree orchestration, PR creation, session memory, learning
extraction, cross-package workflow governance. Those live in `voku/agent-loop`.

## DEFINE

Findings from inspecting the `0.1.0` tree (branch `main` at commit `81bc27c`):

- Three production classes: `TodoBoardSource`, `TodoBoardVerifier`, `TodoBoardCli`,
  plus two value objects (`TodoBoardCard`, `TodoBoardRenderOptions`) and one
  interface (`JiraIssueProvider`).
- **Architectural anti-pattern confirmed**: `TodoBoardSource::readBoardMarkdown()`
  parses per-card Markdown files into arrays, then renders a large, hard-coded
  Markdown document (ALIGN / Board Policy / Kanban Operating Model / Lane Rules /
  Agent Pull Checklist / ...) full of project-specific prose (Jira as source of
  truth, `MEMORY.md`, `make memory_review`, Docker validation). `TodoBoardVerifier`
  and `TodoBoardCli` then **re-parse that generated Markdown** (regex over tables,
  section headers, `_Count: N_` markers) to recover the same facts the source
  objects already had as arrays. This is precisely the "Markdown as internal
  database" pattern the 1.0 architecture forbids (see `docs/architecture.md`).
- Hard-coded, repository-specific invariants baked into generic engine code:
  project prefix examples (`ITPNG`), German Jira status labels (`Selected`,
  `In Planung`, `Warten`, `Fertig`), a fixed WIP limit of `3`, `MEMORY.md` /
  `make memory_review` / Docker instructions, and the claim that Jira is always
  the source of truth. All of this must move out of the engine into optional,
  documented host configuration.
- Compatibility surface that **must** be preserved: `TODO.md` entrypoint,
  `todo/board.md` metadata, `todo/cards/<PREFIX>-<NUMBER>.md` (preferred),
  `todo/jira/<PREFIX>-<NUMBER>.md` (legacy, still supported, `todo/cards` wins when
  both exist), the bullet-metadata card syntax (`- **Field:** value`), and the
  `## Agent Task Brief` section convention.
- No CI matrix beyond PHP 8.3/8.4; no php-cs-fixer; no JSON output; no mutation
  API; no atomic writes; no claim model; no docs beyond `README.md` /
  `CHANGELOG.md`.
- Sandbox note: this session's GitHub network access is scoped to
  `voku/agent-kanban` only; `voku/agent-loop` could not be inspected directly.
  Section 16 work is therefore done as a documented, versioned integration
  *contract* (`docs/agent-loop-integration.md`) rather than a verified diff
  against the actual `agent-loop` source. This is flagged as a residual risk in
  the final deliverables, not hidden.

## DESIGN

Target data flow (see `docs/architecture.md` for the full diagram and rationale):

```
Markdown card files -> MarkdownCardRepository -> immutable Card objects -> immutable Board
    -> query services / verification / Markdown rendering / JSON rendering / mutations
```

Key decisions:

- **Card identity/config as value objects, not native PHP enums.** Lanes and
  statuses are configurable per board (`BoardConfig`), so they cannot be closed
  PHP enums without reintroducing hard-coded policy. They are `final readonly`
  value objects that validate against `BoardConfig` at construction time.
  `Severity` and `ViolationCode` *are* native backed enums because they are
  engine-internal and closed.
- **One card file is one source of truth.** `MarkdownCardRepository` reads each
  card file directly into a `Card`. Nothing reparses rendered output.
- **`todo/board.md` stays a small metadata file** (project prefix, done count),
  now folded into `BoardConfig` resolution instead of being spliced into a giant
  generated document.
- **Card format stays the existing bullet-metadata Markdown**, formalized with a
  written spec (`docs/card-format.md`), not switched to YAML frontmatter. YAML
  frontmatter was considered and rejected for 1.0: it would be a needless format
  migration for existing 0.x boards with no functional gain, violating "no format
  migration unless explicit, reviewable, opt-in" (see UPGRADING.md if this
  changes later).
- **Deprecated facades over deletion — superseded, see below.** The original
  plan (first pass of this rework) kept `TodoBoardCard`,
  `TodoBoardRenderOptions`, `JiraIssueProvider`, `TodoBoardSource`,
  `TodoBoardVerifier`, `TodoBoardCli` as `@deprecated` facades delegating to
  the new engine, preserving their outward method contracts while their
  inward behavior changed to run the new typed engine. That shipped, passed
  the original 0.1.0 test suite unmodified, and was reviewed. **The
  maintainer then explicitly decided to remove these classes outright**
  instead: this package has one known consumer (`voku/agent-loop`), so the
  cost of carrying the old generated-Markdown architecture forward as
  "working but deprecated" code (and its compatibility tests) wasn't worth
  it — a clean break plus a thorough `UPGRADING.md` migration guide was
  judged better. The classes, their tests, and the CLI aliases
  (`ticket`/`context`/`brief`/`jira-sync`) they enabled are deleted. See
  `UPGRADING.md` for the direct replacement for each.
- **No plugin registry for external issue providers.** Only one real
  implementation shape exists (Jira, as a documented example). A registry/adapter
  framework is deferred until a second provider is real (explicit non-goal per
  brief section 15/22).
- **No SQLite/Postgres/server/event sourcing/etc.** Filesystem + Git only.

## IMPLEMENT

Work proceeds bottom-up: domain model -> config -> repository -> rendering ->
verification -> queries -> transitions -> mutations -> external issues -> CLI ->
deprecated facades -> tests -> quality tooling -> docs. Tracked task-by-task in
the session's task list; each phase's smallest coherent diff is committed
separately where practical.

## VERIFY

Before considering the engine done for a phase:

- `composer test` (PHPUnit) green.
- `composer phpstan` (max level) green, or a narrow, commented
  `@phpstan-ignore-next-line` with a one-line reason.
- `composer cs-check` (php-cs-fixer dry-run) clean.
- Manual CLI smoke test against `tests/fixtures/project-root` and a second
  synthetic non-Jira board.
- Existing 0.x fixtures still parse and still verify/render through the
  deprecated facades.

Sandbox constraint encountered: this session's Composer/GitHub egress is scoped
to `voku/agent-kanban`, which blocks Composer's GitHub dist-zip downloads for
dev dependencies not already vendored (`phpstan/phpstan`, `friendsofphp/php-cs-fixer`).
Plain `git clone` over HTTPS to github.com is *not* blocked, so dependencies are
installed by forcing Composer's VCS/source install path. This is a local,
temporary `composer.json` `repositories` override for this session only — see
the "Known limitations" note in the release notes; a normal developer machine or
CI runner needs no such workaround.

## DOCUMENT

`README.md`, `CHANGELOG.md`, `UPGRADING.md`, `CONTRIBUTING.md`, `SECURITY.md`,
and the `docs/*.md` set enumerated in the brief. Updated alongside the code that
motivates them, not as a final pass.

## RELEASE

**No tag, no publish.** Per explicit instruction, this work targets a stable,
usable *API* for building a UI and for `voku/agent-loop`/colleagues to consume
next — not a published 1.0.0. Version progression in `composer.json`/
`CHANGELOG.md` stays pre-1.0 (`0.x`) until the repository owner decides to cut
1.0.0 for real, after running it in further real repositories and completing an
RC/compatibility-freeze cycle, per brief section 21.

## Decisions log (accepted / rejected)

| Decision | Status | Why |
| --- | --- | --- |
| Keep bullet-metadata Markdown card format | Accepted | No functional need to migrate; avoids a disruptive format change for existing 0.x boards. |
| Switch to YAML frontmatter | Rejected (for 1.0) | Fashionable, not necessary; violates "explicit, reviewable, opt-in" migration rule. |
| Lanes/Status as native PHP enums | Rejected | Would hard-code configuration the brief requires to stay host-configurable. |
| Keep `TodoBoard*` classes, deprecated | Reversed (superseded) | Shipped once as deprecated facades; the maintainer then decided this package's one known consumer (`voku/agent-loop`) didn't justify carrying the old generated-Markdown architecture forward, and chose a clean break plus `UPGRADING.md` migration guide instead. See DESIGN section above. |
| Generic adapter/plugin registry for external issue providers | Rejected (for now) | Only one real implementation (Jira) exists; brief explicitly defers this until a second real provider exists. |
| SQLite/Postgres/event sourcing/server | Rejected | Explicit non-goal; filesystem + Git is the whole point. |
