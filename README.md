# agent-kanban

A strict PHP library and CLI for **Git-native, coding-agent Kanban boards**:
human-readable and human-editable Markdown card files, one file per task,
deterministic parsing and verification, safe conflict-aware mutations, and
machine-readable (JSON) output for coding agents and CI.

Local-first, offline-capable, auditable through Git, and independent of any
specific LLM or coding-agent provider.

## What this is

- A typed PHP API (`docs/php-api.md`) plus a standalone CLI
  (`vendor/bin/agent-kanban`, `docs/cli.md`) for reading, verifying,
  rendering, and safely mutating a Kanban board made of Markdown card
  files.
- A normative card format (`docs/card-format.md`): one Markdown file per
  card, `- **Field:** value` bullet metadata, deterministic serialization.
- A configurable board policy (`docs/configuration.md`): lanes, statuses,
  WIP limits, required fields, transitions — nothing project-specific is
  hard-coded into the engine.
- Optional, credential-free comparison against an external tracker like
  Jira (`docs/external-issues.md`) — bring your own adapter, no network
  code shipped in this package.
- A stable foundation to build on: a UI, `voku/agent-loop`'s session
  orchestration, or your own tooling, all consuming the same typed
  contracts (`docs/agent-loop-integration.md`).

## What this is not

- Not an agent execution platform: it does not start, run, or talk to
  LLMs or coding agents.
- Not a UI: no browser, desktop app, or drag-and-drop board.
- Not a database: no SQLite/Postgres/server, no event sourcing, no
  WebSockets, no terminal streaming.
- Not Git worktree orchestration or PR automation.
- Not session memory, recall compilation, learning extraction, or
  cross-package workflow governance — that's `voku/agent-loop`'s job.

See `docs/architecture.md` for the full design and `docs/PLAN.md` for the
reasoning behind these boundaries.

## Installation

```bash
composer require voku/agent-kanban
```

Requires PHP 8.3+.

## Five-minute setup

```bash
mkdir -p todo/cards
cat > todo/cards/ABC-1.md <<'MD'
# ABC-1: Set up the board

- **Ticket:** ABC-1
- **Lane:** READY
- **Status:** Selected
- **Summary:** First card on the new board.
- **Priority:** 1

## Agent Task Brief
Nothing to do yet — this is just the first card.
MD

vendor/bin/agent-kanban summary
vendor/bin/agent-kanban verify
vendor/bin/agent-kanban card claim ABC-1 --by=codex --move-to-doing
```

That's a complete, working board: no `todo/board.md`, no config file
required (the project prefix `ABC` is inferred from the card filename).
Add a `todo/kanban.config.json` once you want to customize lanes, WIP
limits, or required fields — see `docs/configuration.md`.

## Card file example

```markdown
# ITPNG-123: Implement secure form validation

- **Ticket:** ITPNG-123
- **Lane:** READY
- **Status:** Selected
- **Domain:** Security
- **Assignee:** Lars Moelleken
- **Updated:** 2026-06-09T11:32:00+00:00
- **Summary:** Short summary of the work.
- **Next:** Write unit tests for the validator.
- **Validation:** Run make test_unit.
- **Priority:** 1
- **Wave:** Wave 1

## Handoff / Context
Additional context notes for whoever picks this up next.

## Agent Task Brief
#### ITPNG-123: Implement secure form validation
Details an agent needs before starting.
```

Full field reference, escaping rules, and invalid-input behavior:
`docs/card-format.md`.

## Main CLI commands

```bash
vendor/bin/agent-kanban summary
vendor/bin/agent-kanban verify --format=json
vendor/bin/agent-kanban render --lanes=READY,DOING --search=security
vendor/bin/agent-kanban card show ITPNG-123
vendor/bin/agent-kanban card claim ITPNG-123 --by=codex --move-to-doing
vendor/bin/agent-kanban card move ITPNG-123 --to=VERIFY
vendor/bin/agent-kanban card release ITPNG-123 --by=codex
```

Full command reference, options, and exit codes: `docs/cli.md`.

## Basic PHP usage

```php
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Verification\BoardVerifier;

$config = BoardConfig::default('ITPNG');
$repository = new MarkdownCardRepository($rootPath, $config);
$board = new Board($config, $repository->loadAll(), $repository->resolveCardDirectory());

$next = (new BoardQueryService($board))->nextPullCandidates();

$report = (new BoardVerifier())->verify($board);
if (!$report->isValid()) {
    foreach ($report->errors() as $violation) {
        // $violation->code, ->message, ->severity, ->cardId, ->field, ->file
    }
}
```

Mutating a card:

```php
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\Lane;

$mutation = new CardMutationService($rootPath, $config, $repository);
$result = $mutation->move(CardId::fromString('ITPNG-123'), Lane::fromString('DOING'), actor: 'codex');
// $result->newRevision, $result->transition, ...
```

Full API tour: `docs/php-api.md`.

## Compatibility

- `todo/cards/` (preferred) and `todo/jira/` (legacy) are both read;
  `todo/cards/` wins in full when both exist. Existing 0.x card files —
  including the legacy `Fit` field and `Next pull rank` field — keep
  parsing without any migration.
- No card file is ever silently rewritten into a new format by reading it.
- The pre-1.0 `TodoBoardSource`/`TodoBoardVerifier`/`TodoBoardCli` PHP classes
  and their generated-Markdown-based CLI are **removed**, not deprecated —
  this project has one known consumer (`voku/agent-loop`) and the maintainer
  chose a clean break over carrying that implementation forward. See
  `UPGRADING.md` for the direct replacement for each removed class and CLI
  command.

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — data flow and design
- [`docs/card-format.md`](docs/card-format.md) — the card file specification
- [`docs/configuration.md`](docs/configuration.md) — `BoardConfig` reference
- [`docs/cli.md`](docs/cli.md) — full CLI command/option/exit-code reference
- [`docs/php-api.md`](docs/php-api.md) — PHP API tour
- [`docs/json-format.md`](docs/json-format.md) — versioned JSON output shapes
- [`docs/concurrency.md`](docs/concurrency.md) — transitions, claims, atomic writes
- [`docs/external-issues.md`](docs/external-issues.md) — optional Jira-style sync
- [`docs/agent-loop-integration.md`](docs/agent-loop-integration.md) — integrating with `voku/agent-loop`
- [`docs/legacy-operating-prompt.md`](docs/legacy-operating-prompt.md) — the removed pre-1.0 operating-prompt prose, preserved for `agent-recall-compiler`
- [`docs/troubleshooting.md`](docs/troubleshooting.md) — common errors and fixes
- [`UPGRADING.md`](UPGRADING.md) — migrating from 0.x
- [`CHANGELOG.md`](CHANGELOG.md), [`CONTRIBUTING.md`](CONTRIBUTING.md), [`SECURITY.md`](SECURITY.md)

## Development

```bash
composer install
composer test       # PHPUnit
composer phpstan    # PHPStan, max level
composer cs-check    # php-cs-fixer, dry-run
composer cs-fix      # php-cs-fixer, apply fixes
composer ci          # everything composer ci runs in CI
```
