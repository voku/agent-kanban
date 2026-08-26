# AGENTS.md

## Repository role

`voku/agent-kanban` owns the Git-native board model: Markdown card parsing/rendering, board configuration, deterministic verification/querying, and safe conflict-aware card mutations.

It owns board truth and board-policy semantics only. It is not an execution engine, workflow orchestrator, Session store, Recall compiler, Learning store, UI, or external-tracker client.

## Dependency direction

This package is a low-level owner and has no runtime dependency on the other `agent-*` workflow packages. Preserve that direction.

- Higher-level hosts such as `agent-loop` and `agent-ui` should consume the typed PHP API rather than parse Markdown or CLI JSON themselves.
- Project-specific lanes, transitions, WIP limits, and required fields belong in board configuration, not hard-coded engine branches.
- External issue trackers are comparison/input concerns supplied by adapters; do not add credentials, network clients, or tracker authority to this package.

## Invariants to preserve

- One Markdown file is the human-editable durable representation of one card; parsing and deterministic serialization must agree.
- Board policy comes from typed/configured rules. Do not infer workflow lifecycle authority from lane/status names.
- Mutations must remain conflict-aware and must not silently overwrite concurrent edits or invalid board state.
- JSON/compact projections are derived machine views of the same card/board truth, not a second database or schema owner.
- Legacy compatibility may be read explicitly, but do not add hidden dual-write or migration behavior without an intentional compatibility decision.
- Board state may inform orchestration; it does not approve a Contract, authorize code mutation, declare validation success, or close a governed Run.

## Implementation guidance

Keep the engine local-first, deterministic, and offline-capable. Prefer typed identifiers/value objects and owner services such as board queries/verifiers/mutation services. Consumers needing a new board fact should receive a typed projection rather than reconstructing it from file layout or rendered output.

Avoid adding databases, event buses, frontend concerns, Git worktree orchestration, or PR automation here.

## Validation

Run:

```bash
composer ci
```

This includes strict Composer validation, PHPUnit, PHPStan, and php-cs-fixer check mode.

## Releases

Releases are marker-driven. `.release/<version>.json` must identify a release-ready ancestor commit whose own `CHANGELOG.md` contains that release section. Existing tags are immutable.