# Architecture

## Data flow

```text
Markdown card files
    |
    v
MarkdownCardRepository            (Repository\)
    |  parses each file directly into a Card
    v
immutable Card objects            (Domain\Card, CardId, Lane, CardStatus, ...)
    |
    v
immutable Board                   (Board, BoardSnapshot)
    |
    +--> BoardQueryService        (Query\)          read-only lookups
    +--> BoardVerifier            (Verification\)   structured violations
    +--> BoardRenderer            (Rendering\)       Markdown output
    +--> JsonBoardRenderer        (Rendering\)       versioned JSON output
    +--> CardMutationService      (Mutation\)        atomic, conflict-aware writes
```

Every arrow points **one way**. Rendering and JSON output are projections of
the typed `Board` — nothing in this package ever parses `BoardRenderer` or
`JsonBoardRenderer` output back into a `Board`. That round-trip does not
exist, on purpose.

## Why this matters (and what it replaces)

The pre-1.0 implementation did the opposite: `TodoBoardSource` parsed card
files into arrays, then rendered a large, project-specific Markdown document
(Jira-as-source-of-truth prose, `MEMORY.md` instructions, a hard-coded WIP
limit, German Jira status names). `TodoBoardVerifier` and `TodoBoardCli` then
**re-parsed that generated document** with regexes over Markdown tables and
`_Count: N_` markers to recover facts the source objects already had as
plain arrays.

This is the "Markdown as internal database" anti-pattern: two representations
of the same facts (the array and the rendered text) that can drift apart, and
a verifier that is really checking "did my own renderer's template not
change" rather than "is this board healthy." The 1.0 architecture removes it
entirely. `TodoBoardSource`, `TodoBoardVerifier`, and `TodoBoardCli` — the
classes that implemented this pattern — are deleted rather than kept as
compatibility facades (this package has one known consumer, and a clean
break was preferred over carrying the old architecture forward); see
`UPGRADING.md` for the direct typed-engine replacement for each.

## Layers

- **`Domain\`** — immutable value objects with no filesystem or rendering
  knowledge: `CardId`, `Lane`, `CardStatus`, `CardRevision`, `Claim`,
  `ExternalIssueRef`, `Card`, `CardCollection`. `Card` and `CardCollection`
  are the only aggregate/collection types; everything else is a small,
  validated scalar wrapper.
- **`Config\`** — `BoardConfig`: the only place host-specific policy lives
  (project prefix, lanes, status-to-lane mapping, WIP limits, required
  fields, transitions, format version, archive directory). See
  `docs/configuration.md`.
- **`Repository\`** — `CardParser` and `CardSerializer` implement the card
  format (`docs/card-format.md`) as pure, stateless transformations.
  `MarkdownCardRepository` is the only class that touches the filesystem for
  cards: it resolves which directory is active, lists files, parses them
  (strict or lenient), and performs atomic writes/moves.
- **`Query\`** — `BoardQueryService` and its small result types
  (`BoardSummary`, `WipHealth`, `WipGroupStatus`): typed, read-only board
  queries over an already-loaded `Board`.
- **`Verification\`** — `BoardVerifier` produces a `VerificationReport` of
  `Violation`s (each with a stable `ViolationCode`, a `Severity`, and
  optional card/field/file context). Never writes to STDOUT/STDERR.
- **`Rendering\`** — `BoardRenderer` (Markdown) and `JsonBoardRenderer`
  (versioned JSON). Pure output projections.
- **`Transition\`** — `TransitionPolicy` validates lane-to-lane moves against
  `BoardConfig::$transitions`; `TransitionResult` reports what changed.
  Validation is a separate step from writing (see `docs/concurrency.md`).
- **`Mutation\`** — `CardMutationService`: the only place that writes card
  files, always atomically, always revision-checked when asked, always
  either fully succeeding or leaving the original file untouched.
- **`ExternalIssue\`** — `ExternalIssueProvider` (interface, implemented by
  the host), `ExternalIssueComparator` (structured drift between local cards
  and remote records). No network code, no Jira-specific strings, anywhere
  in this namespace. See `docs/external-issues.md`.
- **`Cli\`** — `CliApplication` and its small collaborators
  (`ArgvParser`, `BoardContextFactory`, `OutputFormat`). Every command is a
  few lines that call into the layers above; no business logic lives here.
  See `docs/cli.md`.
- **`Exception\`** — one exception hierarchy
  (`AgentKanbanException` base; `ValidationException`, `ConflictException`,
  `IoException`, `ConfigurationException`, `ExternalProviderException`,
  `NotFoundException`) so callers can catch broadly or narrowly.

## Design choices worth knowing

- **Lane and CardStatus are value objects, not native `enum`s.** Lanes and
  statuses are host-configurable per board, so a closed PHP enum would
  reintroduce hard-coded policy. `Severity` and `ViolationCode` *are* native
  backed enums, because those are closed, engine-internal vocabularies.
- **One card file is one source of truth.** Nothing else is treated as
  authoritative. `todo/board.md` is a small, separate metadata file (done
  count, optional prefix/source override) — see `BoardMetadata` — not
  spliced into a big document.
- **Verification never throws for a violation.** A malformed card file
  becomes a `Violation` (via `MarkdownCardRepository::loadAllLenient()` +
  `BoardVerifier`), not a crash — so one bad file never hides every other
  problem on the board. Code paths that assume a valid board
  (`loadAll()`, rendering, mutations) still throw immediately on the first
  problem, because those callers have a different contract: they need a
  valid board to proceed at all.
