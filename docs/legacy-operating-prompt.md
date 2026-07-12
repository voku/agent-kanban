# The removed "L2 operating prompt" (for `agent-recall-compiler`)

This document exists for exactly one purpose: giving `voku/agent-recall-compiler`
a faithful, complete record of a piece of prose that used to live inside
`agent-kanban` (`TodoBoardSource::buildBoardMarkdown()`, pre-1.0) and was
**deliberately removed**, not merged into the new engine. It was never a data
render — it was a static block of process instructions telling a coding agent
*how to behave* around the board. That's policy, not board state, so it does
not belong in this package (see `docs/architecture.md` and `docs/PLAN.md`).
It is documented here, verbatim, so it can be rebuilt in `agent-recall-compiler`
and injected by `agent-loop` instead of being lost.

## What it was

The old `TodoBoardSource::readBoardMarkdown()` parsed card files into arrays,
then rendered one large Markdown document with two kinds of content mixed
together:

1. **Data** — WIP Health, Board Snapshot, Domain Map, Next Pull Candidates,
   Suggested Execution Waves, the lane tables, Agent Task Briefs, Blocked
   Cards, Backlog Pickup Notes. This is exactly what `agent-kanban` 1.0
   already produces generically today, from typed data instead of hand-built
   strings — see the mapping table below.
2. **A fixed operating prompt** — ALIGN, Source, Board Policy, Kanban
   Operating Model, Lane Rules, Card Update Protocol, Agent Pull Checklist,
   Context Model. This is the part with no equivalent left anywhere in
   `agent-kanban` 1.0, and the part this document preserves.

Calling this "L2" (as distinct from the base system prompt an agent already
runs with, and the raw board data) is `agent-loop`/`agent-recall-compiler`
terminology, not an `agent-kanban` concept — this package just used to render
it as part of one big Markdown blob.

## The prompt, verbatim (as it existed pre-1.0)

Values marked `{{...}}` were hard-coded literals in the old source, not
configuration — they're marked here as the parameters a compiler in
`agent-recall-compiler` should take instead of hard-coding again.

```markdown
### ALIGN

- Problem: keep repository-local TODO work readable by storing active work in topic files.
- Criteria: `{{cardGlob}}` is the source of truth for board cards; `TODO.md` is only the entrypoint.
- Constraints: no raw Jira comments, attachments, full descriptions, secrets, or customer data in tracked repo docs; production stability and privacy first.

### Source

- Board source: `{{source}}`
- TODO entrypoint: `TODO.md`
- Verifier: `{{verifyCommand}}`

### Board Policy

- WIP limit for agent implementation: `{{wipLimit}}` cards
- Pull rule: pull from `READY` only after current implementation WIP is below the limit.
- Done rule: code change is done only after targeted validation in Docker, `{{externalIssueSystem}}` outcome sync, a compact `{{memoryFile}}` entry, and a `{{memoryReviewCommand}}` pass before pruning the card file from `{{cardDirectory}}/`.
- Privacy rule: use `{{externalIssueSystem}}` keys and summaries here; reopen `{{externalIssueSystem}}` for full request details instead of copying payloads.
- Breaking-change rule: forbidden without explicit user approval and ADR.

### Kanban Operating Model

1. `{{externalIssueSystem}}` remains the source of truth for priority, full descriptions, comments, attachments, stakeholder discussion, and flow metrics.
2. `{{cardGlob}}` is the source of truth for repository-local execution state.
3. `TODO.md` is the short entrypoint only; do not add long task bodies there.
4. Work starts from `READY`; do not pull directly from `BACKLOG` into implementation without first refining the card.
5. Keep implementation WIP at `{{wipLimit}}` cards across `READY`, `DOING`, and `VERIFY` work selected for an execution wave.
6. Every movement must update exactly one card file and then run `{{verifyCommand}}`.

### Lane Rules

| Lane | Who uses it | Entry condition | Exit condition | Required fields |
| --- | --- | --- | --- | --- |
| READY | Devs and agents | `{{externalIssueSystem}}` card was reopened, scope is code-adjacent, acceptance criteria are known enough to brief | Implementation starts or card is blocked/deferred | Ticket, Status, Domain, Assignee, Updated, Fit, Summary, Agent Task Brief |
| DOING | Devs and agents | Someone is actively implementing or splitting the card | Code is ready for verification or card is blocked | Ticket, Status, Domain, Assignee, Updated, Fit, Summary |
| VERIFY | Devs and agents | Implementation exists and needs regression/manual/acceptance evidence | `{{externalIssueSystem}}` can be updated as done or work returns to DOING | Ticket, Status, Domain, Assignee, Updated, Fit, Summary |
| BLOCKED | Devs and leads | External decision, missing acceptance detail, dependency, or security/product question blocks execution | Blocker is resolved and card moves to READY/DOING/BACKLOG | Ticket, Status, Domain, Assignee, Updated, Fit, Summary, Blocked Cards prompt |
| BACKLOG | Devs and leads | Card is code-adjacent but not yet refined for execution | Card is selected for a scoped wave and moved to READY | Ticket, Status, Domain, Assignee, Updated, Fit, Summary |

### Card Update Protocol

1. Reopen the `{{externalIssueSystem}}` card and verify the tracked summary is still accurate.
2. Edit exactly one file under `{{cardDirectory}}/`.
3. Keep `Lane`, `Status`, `Fit`, `Next`, and validation fields in that file consistent.
4. If the card is leaving the active board as done, add a compact `{{memoryFile}}` entry, run `{{memoryReviewCommand}}`, and then prune the card file.
5. Run `{{verifyCommand}}`.

### Agent Pull Checklist

- [ ] The card is in `READY`.
- [ ] The card has an Agent Task Brief.
- [ ] `{{externalIssueSystem}}` was reopened for full context.
- [ ] If the card was touched before, the existing Agent Task Brief / repo-local handoff was read before fresh searching.
- [ ] Existing implementation was searched with `rg`.
- [ ] Security/privacy constraints are understood.
- [ ] Validation commands are known before code changes.
- [ ] The intended change fits within a single small execution wave.

### Context Model

- Raw sources: `{{externalIssueSystem}}`, code, ADRs, runtime observations.
- Compiled context: Agent Task Briefs plus repo-local handoff bullets for touched cards.
- Board index: lane tables, Next Pull Candidates, Blocked Cards, and Backlog Pickup Notes.
- Query rule: before re-screening a touched card, read the board index and existing compiled context first.
- Ingest rule: when a card is screened, narrowed, blocked, or moved to `VERIFY`, refresh the repo-local handoff so the next pass does not repeat the same investigation.
```

The original also had two hard-coded metrics baked into the "WIP Health" data
section (`Over proposed agent WIP limit of 3`) and hard-coded German
`{{externalIssueSystem}}` status labels in "Board Snapshot" (`Selected for
Development`, `In Planung`, `in Progress`, `in Test`, `Warten`, `Fertig`) —
those are data-section artifacts of the same hard-coding problem, not part of
the operating prompt above, and are covered by the mapping table below instead.

## Why this was removed from `agent-kanban`, not kept

- It is entirely project-specific policy (a WIP limit of `3`, a specific
  external-tracker name, a `MEMORY.md` file, a `make memory_review` command,
  a `make todo_board_verify` command) hard-coded into what was supposed to be
  a generic engine. `agent-kanban` 1.0's rule is that **nothing
  project-specific is hard-coded into the engine** — see `BoardConfig`
  (`docs/configuration.md`).
- It was produced by re-parsing/re-generating a Markdown document rather than
  being derived from typed data on demand — the exact "Markdown as internal
  database" anti-pattern `agent-kanban` 1.0 removes (`docs/architecture.md`).
- It mixes two different concerns — *live board data* and *static operating
  instructions* — that have very different lifecycles: data changes every
  time a card moves; the operating prompt changes only when the team's
  process changes. Splitting them lets each evolve independently.

## Recommended new home: `voku/agent-recall-compiler`

This operating prompt should become a **template** in
`agent-recall-compiler`, parameterized by the `{{...}}` values above, with
its data placeholders (WIP Health numbers, Board Snapshot, Domain Map, Next
Pull Candidates, Wave tables, lane tables, task briefs, Blocked Cards,
Backlog Pickup Notes) filled in from **live calls into `agent-kanban`'s
typed API** — never hand-rolled strings, and never by re-parsing
`agent-kanban`'s own rendered output. `agent-loop` would call the compiler
(not `agent-kanban`) when it needs this prompt for a kanban-board-maintenance
session, and inject the result into the agent's context alongside whatever
board data it also needs (typically `JsonBoardRenderer` output — see
`docs/json-format.md`).

### Old generated section → typed `agent-kanban` data source

| Old section (pre-1.0) | Use this instead (1.0) |
| --- | --- |
| WIP Health | `Query\BoardQueryService::wipHealth()` → `WipHealth`/`WipGroupStatus` |
| Board Snapshot | `Query\BoardQueryService::byStatus()` / `byLane()` counts, or `Rendering\JsonBoardRenderer::summaryToArray()` |
| Domain Map | `Query\BoardQueryService::byDomain()` |
| Next Pull Candidates | `Query\BoardQueryService::nextPullCandidates()` |
| Suggested Execution Waves | `Domain\Card::$wave` grouped client-side (no dedicated query yet; group `BoardQueryService::search()`/`byLane()` results by `wave`) |
| Kanban Board (lane tables) | `Rendering\BoardRenderer::renderFull()` / `renderFiltered()`, or `JsonBoardRenderer::cardsToEnvelope()` |
| Agent Task Briefs | `Domain\Card::$taskBrief` |
| Blocked Cards | `Query\BoardQueryService::byLane(Lane::fromString('BLOCKED'))` |
| Backlog Pickup Notes | `Query\BoardQueryService::byLane(Lane::fromString('BACKLOG'))` |
| WIP limit `3` | Your own `BoardConfig::$wipLimits` |
| `{{externalIssueSystem}}` name/status labels | Your own `BoardConfig::$statusToLane` + `ExternalIssue\ExternalIssueProvider` (`docs/external-issues.md`) |
| `make todo_board_verify` | `Verification\BoardVerifier::verify()` (call it, then have your own tooling wrap the CLI/make target) |
| `MEMORY.md` / `make memory_review` | Entirely `agent-loop`/`agent-recall-compiler` concerns — no `agent-kanban` equivalent, and none is planned. |

None of these `agent-kanban` calls need `agent-recall-compiler` to parse
Markdown at all — they return typed PHP objects or the versioned JSON shapes
in `docs/json-format.md`. See `docs/agent-loop-integration.md` for the full
integration contract and more usage examples.
