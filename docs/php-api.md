# PHP API

This page is a tour of the stable, typed public API. See `docs/architecture.md`
for how the pieces fit together and `docs/concurrency.md` for the mutation/
claim model in depth.

## Reading a board

```php
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\MarkdownCardRepository;

$config = BoardConfig::default('ITPNG'); // or BoardConfig::fromJsonFile(...)
$repository = new MarkdownCardRepository($rootPath, $config);

$board = new Board(
    $config,
    $repository->loadAll(),                       // throws on the first malformed card
    $repository->resolveCardDirectory() ?? $config->cardDirectory,
);
```

Use `loadAllLenient()` instead of `loadAll()` when you want every problem on
the board, not just the first:

```php
$result = $repository->loadAllLenient(); // CardLoadResult { cards, failures }
```

## Querying

```php
use voku\AgentKanban\Query\BoardQueryService;

$query = new BoardQueryService($board);

$query->summary();                 // BoardSummary: lane counts, total, done count
$query->byLane('READY');           // list<Card>
$query->byStatus('Selected');      // list<Card>, case-insensitive
$query->byAssignee('codex');       // list<Card>, case-insensitive
$query->byDomain('Security');      // list<Card>, case-insensitive
$query->search('login form');      // list<Card>, matches id/title/status/domain/assignee/summary/nextAction/wave
$query->nextPullCandidates();      // list<Card>, priority > 0, ascending
$query->blockedCards();            // list<Card> in the BLOCKED lane, if configured
$query->wipHealth();               // WipHealth: per-group counts vs configured limits
$query->get('ITPNG-123');          // ?Card
```

## Verifying

```php
use voku\AgentKanban\Verification\BoardVerifier;

$report = (new BoardVerifier())->verify($board, $loadFailures ?? []);

if (!$report->isValid()) {
    foreach ($report->errors() as $violation) {
        // $violation->code (ViolationCode enum), ->message, ->severity,
        // ->cardId, ->field, ->file — all optional except code/message/severity.
    }
}
```

`BoardVerifier::verify()` never writes to STDOUT/STDERR and never throws for
a violation — only the CLI decides how to present a report.

## Rendering

```php
use voku\AgentKanban\Rendering\BoardRenderer;
use voku\AgentKanban\Rendering\JsonBoardRenderer;

$markdown = (new BoardRenderer())->renderFull($board);
$json = (new JsonBoardRenderer())->encode((new JsonBoardRenderer())->summaryToArray($board));
```

See `docs/json-format.md` for every JSON shape.

## Mutating

```php
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Domain\{CardId, Lane, CardStatus};

$mutation = new CardMutationService($rootPath, $config, $repository);

$mutation->create(CardId::fromString('ITPNG-200'), Lane::fromString('BACKLOG'), CardStatus::fromString('Backlog'), 'Title', 'Summary');
$mutation->update(CardId::fromString('ITPNG-200'), summary: 'New summary');
$mutation->move(CardId::fromString('ITPNG-200'), Lane::fromString('READY'), actor: 'codex');
$mutation->claim(CardId::fromString('ITPNG-200'), actor: 'codex', moveToDoing: true);
$mutation->release(CardId::fromString('ITPNG-200'), actor: 'codex');
$mutation->archive(CardId::fromString('ITPNG-200'));   // requires archiveDirectory
$mutation->restore(CardId::fromString('ITPNG-200'));
```

Every method accepts `dryRun: true` and an optional `expectedRevision:
CardRevision` for optimistic concurrency. Every method returns a
`MutationResult` or throws `ValidationException` / `ConflictException` /
`NotFoundException` / `ConfigurationException`. See `docs/concurrency.md`.

## Domain value objects

| Type | Notes |
| --- | --- |
| `CardId` | `CardId::fromString('ITPNG-123')`, `CardId::of('ITPNG', 123)`. Format-validated only. |
| `Lane` | `Lane::fromString('READY')`. Format-validated (uppercase identifier); membership in a board's configured lanes is a `BoardConfig`/verifier concern. |
| `CardStatus` | `CardStatus::fromString('Selected')`, `CardStatus::none()`. Free text. |
| `CardRevision` | `CardRevision::fromContent($bytes)`, `::fromHex($sha256Hex)`. |
| `Claim` | `actor`, `claimedAt`, `expiresAt`, `revisionAtClaim`. |
| `ExternalIssueRef` | `system`, `key`. |
| `Card` | Immutable; use `$card->with(...)` for a modified copy, or go through `CardMutationService` to persist a change. |
| `CardCollection` | Immutable, unique by `CardId`; `all()`, `get()`, `has()`, `withCard()`, `withoutCard()`, `filter()`. |

## Exceptions

All extend `voku\AgentKanban\Exception\AgentKanbanException`:

- `ValidationException` — bad input or disallowed transition. Carries
  `?cardId`, `?field`.
- `ConflictException` — stale revision or claim conflict. Carries `cardId`,
  `?expectedRevision`, `?actualRevision`.
- `NotFoundException` — card (or archived card) does not exist. Carries
  `?cardId`.
- `IoException` — a filesystem operation failed. Carries `?path`.
- `ConfigurationException` — bad or missing configuration. Carries
  `?configPath`.
- `ExternalProviderException` — an `ExternalIssueProvider` failed. Carries
  `providerName`. Messages never include credentials; the package never
  stores any.

## External issues (optional)

```php
use voku\AgentKanban\ExternalIssue\{ExternalIssueProvider, ExternalIssueComparator};

$drift = (new ExternalIssueComparator())->compare($board->cards, $records, $config);
// $drift->entries: list<ExternalIssueDriftEntry>, each with a DriftKind
```

See `docs/external-issues.md` for implementing `ExternalIssueProvider`.
