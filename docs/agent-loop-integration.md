# Integrating with `voku/agent-loop`

`agent-kanban` owns durable board state: parsing, verification, queries,
rendering and safe card mutations. `agent-loop` owns the cross-package workflow
around that state: deciding when to claim work, starting sessions, preparing
recall, executing and verifying edits, reviewing, learning and closing.

This document describes the current typed integration boundary. It does not
claim that `agent-kanban` owns any part of the larger governed lifecycle.

## Current integration status

Current `agent-loop` delegates its `board` and `board:verify` namespaces to
`voku\AgentKanban\Cli\CliApplication`. The removed pre-1.0
`TodoBoardSource`/`TodoBoardVerifier`/`TodoBoardCli` classes are historical and
are not the current integration path.

The package-local tests below prove the behavior of the typed board engine. A
clean installed-package release-set smoke in `agent-loop` is the required proof
that the complete package set works together in a consumer repository. Package
READMEs agreeing with one another is useful, but it is not executable evidence.

## Ownership boundary

| Concern | Owner |
| --- | --- |
| Parse and serialize card files | `agent-kanban` |
| Validate board policy, claims and transitions | `agent-kanban` |
| Query or render cards | `agent-kanban` |
| Perform atomic card mutations | `agent-kanban` |
| Decide when a workflow should claim/release/move a card | `agent-loop` |
| Session, recall, map, edit, verification and learning lifecycle | `agent-loop` and their focused packages |
| Cross-package run manifest and joined status | `agent-loop` |

`agent-loop` may reference board state, but it must not duplicate or reinterpret
board rules.

## Typed API consumed by an orchestrator

| Need | Type |
| --- | --- |
| Read board configuration | `voku\AgentKanban\Config\BoardConfig` |
| Read cards from disk | `voku\AgentKanban\Repository\MarkdownCardRepository` |
| Look up, filter and search cards | `voku\AgentKanban\Query\BoardQueryService` |
| Verify board health | `voku\AgentKanban\Verification\BoardVerifier` and `VerificationReport` |
| Check a lane transition | `voku\AgentKanban\Transition\TransitionPolicy` |
| Claim or release a card | `voku\AgentKanban\Mutation\CardMutationService` |
| Move/archive/restore a card | `voku\AgentKanban\Mutation\CardMutationService` |
| Render for humans or agents | `BoardRenderer` and `JsonBoardRenderer` |

These types do not start agents, create sessions, compile recall or make network
calls. The orchestrator decides when to use them.

## Reading and verifying a board

```php
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentKanban\Verification\BoardVerifier;

$config = BoardConfig::fromJsonFile($root . '/todo/kanban.config.json');
$repository = new MarkdownCardRepository($root, $config);
$loaded = $repository->loadAllLenient();
$board = new Board($config, $loaded->cards, $repository->resolveCardDirectory());
$report = (new BoardVerifier())->verify($board, $loaded->failures);

if (!$report->isValid()) {
    foreach ($report->errors() as $violation) {
        // Surface the structured code/message/card/field/file evidence.
        // The orchestrator decides whether the workflow may continue.
    }
}
```

`agent-kanban` produces the structured violations. `agent-loop` owns the
cross-package decision and recovery message.

## Pulling and claiming work

```php
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Query\BoardQueryService;

$candidates = (new BoardQueryService($board))->nextPullCandidates();
if ($candidates !== []) {
    $mutation = new CardMutationService($root, $config, $repository);
    $result = $mutation->claim(
        $candidates[0]->id,
        actor: 'agent-loop-session-42',
        moveToDoing: true,
    );
}
```

The card mutation is authoritative for claim and transition validity. A claim
conflict is not silently overwritten by orchestration.

## Transition checks

```php
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Transition\TransitionPolicy;

$policy = new TransitionPolicy($config);
$allowed = $policy->canTransition($card->lane, Lane::fromString('VERIFY'));

if (!$allowed) {
    // Surface the reason. Do not force the move merely to make the workflow green.
}
```

## Conflict handling

Mutation methods may throw:

- `voku\AgentKanban\Exception\ConflictException` for revision or claim
  conflicts;
- `voku\AgentKanban\Exception\ValidationException` for invalid input or
  disallowed transitions.

`agent-loop` catches these at the orchestration boundary and decides whether to
surface, retry after rereading, abandon the claim or require an explicit human
decision. `agent-kanban` deliberately does not own session retry policy.

## Reading a board without spending the context window

An orchestrator reads the board far more often than it writes to it, and a
full card object is mostly prose it does not need in order to decide what to
work on next: `taskBrief` and `handoffNotes` are unbounded, and `revision` is
64 characters. Multiplied by every card in every listing, that is usually the
largest single input an `agent-loop` session feeds to a model.

Both the CLI and the typed API can emit a reduced card object instead.

```bash
vendor/bin/agent-kanban next-pull --format=json --fields=lane,status,priority --compact
```

```php
use voku\AgentKanban\Rendering\CardFieldSelection;
use voku\AgentKanban\Rendering\JsonBoardRenderer;

$renderer = new JsonBoardRenderer();
$selection = CardFieldSelection::fromString('lane,status,priority');

echo $renderer->encode(
    $renderer->cardsToEnvelope($candidates, $selection),
    compact: true,
);
```

The reduced document is a subset of the documented `card` shape, not a new
one: same `schemaVersion`, same envelope, same key order, and `id` is always
present. See `docs/json-format.md` for the exact contract.

Suggested split, given that a session usually needs breadth first and depth
only for the one card it actually picks up:

| Step | Call |
| --- | --- |
| Rank candidates | `next-pull --format=json --fields=lane,status,priority,summary --compact` |
| Survey a lane | `lane DOING --format=json --fields=status,assignee,claim --compact` |
| Read the card it claimed | `card show <ID> --format=json` (full object, one card) |

`agent-kanban` deliberately does not decide which fields an orchestrator
needs, or cache anything on its behalf. It only makes the narrower request
expressible, and rejects a field selection it cannot honor rather than
silently returning less than was asked for.

## Board reference in a governed run

The cross-package run manifest belongs to `agent-loop`. Current `agent-loop`
derives a bounded board reference through `agent-kanban`'s typed board context
and repository rather than copying mutable card state into a second board
model. The projection includes the checked board state, configuration identity
when available, and for a linked card its ID, revision, lane/status and source
artifact.

`agent-kanban` remains authoritative for board parsing, policy and mutations.
A dedicated Run-reference API should only be added if real post-integration
coupling demonstrates that the existing typed boundary is insufficient, not for
symmetry with the other focused packages.

Board state is optional for an ad hoc task unless the chosen workflow mode
requires a card. “No board” and “invalid board” are different states.

## What stays out of `agent-kanban`

Do not add any of the following merely to simplify the umbrella package:

- session lifecycle;
- recall or memory compilation;
- repository maps/search;
- edit execution or verification;
- durable learning extraction or promotion;
- PR creation or Git worktree orchestration;
- cross-package close gates;
- general retry policy.

Those concerns belong to `agent-loop` or another focused package. Reimplementing
them here would create two authorities, which is a surprisingly efficient way
to make both wrong.

## Operating guidance

The removed pre-1.0 generated board Markdown mixed current board data with a
static operating prompt. That policy prose is not board state and does not
belong in this package's runtime model.

The historical text remains in
[`docs/legacy-operating-prompt.md`](legacy-operating-prompt.md) as migration
material. Current managed agent guidance belongs in the `agent-loop` setup and
recall path, with explicit capability/version metadata so it can be checked
against the installed runtime.

## Executable contract fixtures

The following package-local tests define behavior an orchestrator may rely on:

- `tests/Mutation/CardMutationServiceTest.php` for claim, release and conflict
  semantics;
- `tests/Verification/BoardVerifierTest.php` for structured violations;
- `tests/Transition/TransitionPolicyTest.php` for transition policy;
- `tests/Rendering/JsonBoardRendererTest.php` for the versioned JSON envelope;
- `tests/Rendering/CardFieldSelectionTest.php` for reduced card objects.

When `agent-loop` needs a new board behavior, first add or identify an owning
package fixture. The installed release-set smoke then proves the complete
cross-package path. Do not document an integration claim that neither side
executes.
