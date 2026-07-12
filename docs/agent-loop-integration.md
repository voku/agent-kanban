# Integrating with `voku/agent-loop`

`agent-kanban` owns the board: parsing, verification, queries, rendering,
mutations. `agent-loop` owns everything around starting and running a coding
agent (sessions, memory, learning extraction, workflow governance, PR
creation, ...). This document describes the stable, typed contract
`agent-loop` should consume from `agent-kanban`, and where the boundary sits.

> **Scope note.** This document was written from `agent-kanban`'s own public
> API surface and the compatibility fixtures in this repository. The session
> that authored it did not have access to the `voku/agent-loop` source (its
> GitHub access was scoped to `voku/agent-kanban` only for this piece of
> work), so the contract below has **not** been diff-checked against
> `agent-loop`'s actual current usage. Treat it as the intended integration
> surface; before relying on it, run `agent-loop`'s own test suite against
> this branch and confirm nothing in this document contradicts how it
> actually calls into `agent-kanban` today. Anything that does not match
> should be treated as an `agent-kanban` bug to fix, not something
> `agent-loop` should work around — see `UPGRADING.md`.
>
> **Migration status.** The current `voku/agent-loop` codebase still calls
> the pre-1.0 `TodoBoardSource`/`TodoBoardVerifier`/`TodoBoardCli` classes
> and CLI commands, all of which this release removes (see `UPGRADING.md`).
> `agent-loop` is **expected to be broken against this branch until it is
> migrated separately**, in its own follow-up work, onto the typed contract
> described below. This package's CI intentionally does not check out or
> build `agent-loop` — that migration is out of scope here and belongs to
> that follow-up, not to a compatibility shim added to this repository.

## What `agent-loop` should consume

| Need | Type |
| --- | --- |
| Read board configuration | `voku\AgentKanban\Config\BoardConfig` |
| Read cards from disk | `voku\AgentKanban\Repository\MarkdownCardRepository` |
| Look up / filter / search cards | `voku\AgentKanban\Query\BoardQueryService` |
| Check whether a board is healthy before starting work | `voku\AgentKanban\Verification\BoardVerifier` + `VerificationReport` |
| Check whether a lane move is allowed | `voku\AgentKanban\Transition\TransitionPolicy` |
| Claim a card before starting a session | `voku\AgentKanban\Mutation\CardMutationService::claim()` |
| Release a claim when a session ends | `voku\AgentKanban\Mutation\CardMutationService::release()` |
| Move a card as work progresses | `voku\AgentKanban\Mutation\CardMutationService::move()` |
| Render a card or board for display | `voku\AgentKanban\Rendering\BoardRenderer` / `JsonBoardRenderer` |

None of these types touch a network, a process, or a session. `agent-loop`
is expected to own the actual decision of *when* to call them (e.g. "claim
the next-pull candidate, then start a session").

## Example: pulling and claiming the next card

```php
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Mutation\CardMutationService;

$config = BoardConfig::fromJsonFile($root . '/todo/kanban.config.json');
$repository = new MarkdownCardRepository($root, $config);
$board = new \voku\AgentKanban\Board($config, $repository->loadAll(), $repository->resolveCardDirectory());

$candidates = (new BoardQueryService($board))->nextPullCandidates();
if ($candidates === []) {
    return; // nothing to do
}

$card = $candidates[0];
$mutation = new CardMutationService($root, $config, $repository);
$result = $mutation->claim($card->id, actor: 'agent-loop-session-42', moveToDoing: true);

// $result->card->lane is now DOING (if the transition was allowed);
// $result->warnings explains why not, otherwise.
```

## Example: verifying before/after a session

```php
use voku\AgentKanban\Verification\BoardVerifier;

$lenient = $repository->loadAllLenient();
$board = new \voku\AgentKanban\Board($config, $lenient->cards, $repository->resolveCardDirectory());
$report = (new BoardVerifier())->verify($board, $lenient->failures);

if (!$report->isValid()) {
    foreach ($report->errors() as $violation) {
        // surface $violation->code, ->message, ->cardId, ->field, ->file
        // to the agent-loop session log; agent-loop decides whether this
        // blocks starting/finishing a session.
    }
}
```

## Example: validating a transition before agent-loop marks work done

```php
use voku\AgentKanban\Transition\TransitionPolicy;
use voku\AgentKanban\Domain\Lane;

$policy = new TransitionPolicy($config);
if (!$policy->canTransition($card->lane, Lane::fromString('VERIFY'))) {
    // agent-loop should not silently force the move; surface this to the
    // session instead.
}
```

## Conflict handling

`CardMutationService` methods throw
`voku\AgentKanban\Exception\ConflictException` (revision or claim conflicts)
and `voku\AgentKanban\Exception\ValidationException` (bad input, disallowed
transition). `agent-loop` should catch these at the session-orchestration
boundary and decide how to react (retry, surface to the user, abandon the
claim) — `agent-kanban` deliberately has no opinion on retry policy or
session lifecycle.

## What stays in `agent-loop`

Per `README.md`'s product boundary, `agent-kanban` never gains: session
lifecycle, recall/memory compilation, learning extraction, durable
project-memory promotion, PR creation, Git worktree orchestration, or
cross-package workflow governance. If an integration need would require
adding one of these to `agent-kanban`, it belongs in `agent-loop` instead,
consuming the typed contract above.

## The old operating-prompt prose (moving to `agent-recall-compiler`)

The pre-1.0 generated board Markdown mixed live board data with a static
block of process instructions (WIP policy, pull rules, an "Agent Pull
Checklist", etc.). That prose was project-specific policy, not board state,
so it has no home in `agent-kanban` and was deleted rather than rebuilt here
(see `UPGRADING.md`). It is preserved verbatim, with a mapping from each old
section to the typed `agent-kanban` call that should feed it, in
`docs/legacy-operating-prompt.md` — use that as the source document when
rebuilding this as a template in `agent-recall-compiler` for `agent-loop` to
call before/during kanban-maintenance sessions.

## Contract fixtures

The following existing tests double as executable contract fixtures for
integration purposes — they demonstrate the exact behavior `agent-loop` can
depend on:

- `tests/Mutation/CardMutationServiceTest.php` — claim/release/conflict
  semantics.
- `tests/Verification/BoardVerifierTest.php` — violation shape and codes.
- `tests/Transition/TransitionPolicyTest.php` — the default transition
  graph.
- `tests/Rendering/JsonBoardRendererTest.php` — the JSON envelope shape
  (schema version, field names).

If `agent-loop` needs a scenario not covered here, the preferred path is to
add a fixture/test in this repository (or an equivalent contract test in
`agent-loop`) rather than relying on undocumented behavior.
