# JSON format specification (schema version 1)

Every JSON document this package produces (`JsonBoardRenderer` and the CLI's
`--format=json`) is a flat object with three fields present on every shape:

```json
{
  "schemaVersion": 1,
  "type": "<shape name>",
  "generatedAt": "2026-07-12T09:00:00+00:00"
}
```

`schemaVersion` (`JsonBoardRenderer::SCHEMA_VERSION`) only changes on a
breaking shape change, and a breaking change will be called out in
`CHANGELOG.md` / `UPGRADING.md`. `type` identifies the shape (see table
below) so a consumer can dispatch without guessing from field presence.
`generatedAt` is ISO-8601 with a numeric UTC offset.

No JSON document produced by this package ever includes an exception trace,
a file-system path outside what is explicitly documented below, or
credentials of any kind.

## Shapes

| `type` | Produced by |
| --- | --- |
| `board-summary` | `agent-kanban summary --format=json`, `JsonBoardRenderer::summaryToArray()` |
| `card` | `agent-kanban card show --format=json`, `JsonBoardRenderer::cardToEnvelope()` |
| `card-list` | `agent-kanban render/lane/next-pull --format=json`, `JsonBoardRenderer::cardsToEnvelope()` |
| `verification-report` | `agent-kanban verify --format=json`, `JsonBoardRenderer::verificationReportToArray()` |
| `mutation-result` | `agent-kanban card create/update/move/claim/release/archive/restore --format=json` |
| `card-brief` | `agent-kanban brief --format=json` |
| `external-issue-drift` | `agent-kanban external-sync --format=json` |
| `error` | Any command that throws `ValidationException` / `NotFoundException` / `ConfigurationException` / `ExternalProviderException` with `--format=json` |
| `conflict-error` | Any command that throws `ConflictException` with `--format=json` |

### `board-summary`

```json
{
  "schemaVersion": 1,
  "type": "board-summary",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "projectPrefix": "ITPNG",
  "laneCounts": { "BACKLOG": 1, "READY": 2, "DOING": 1, "VERIFY": 0, "BLOCKED": 0 },
  "totalCards": 4,
  "doneCount": 301,
  "formatVersion": 1
}
```

### `card` (the `card` object shape is reused inside `card-list`)

```json
{
  "schemaVersion": 1,
  "type": "card",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "card": {
    "id": "ITPNG-123",
    "title": "Implement secure form validation",
    "lane": "READY",
    "status": "Selected",
    "domain": "Security",
    "assignee": "Lars Moelleken",
    "createdAt": "2026-06-01T09:00:00+00:00",
    "updatedAt": "2026-06-09T11:32:00+00:00",
    "summary": "Short summary of the work.",
    "nextAction": "Write unit tests for the validator.",
    "validation": "Run make test_unit.",
    "priority": 1,
    "wave": "Wave 1",
    "taskBrief": "#### ITPNG-123: ...\nDetails...",
    "handoffNotes": "",
    "claim": null,
    "externalIssue": { "system": "jira", "key": "ITPNG-123" },
    "formatVersion": 1,
    "extensionFields": { "Fit": "Recommended" },
    "revision": "3f2504e0...b3c4d (64 hex chars)",
    "sourceFile": "todo/cards/ITPNG-123.md"
  }
}
```

`claim`, when present, is
`{ "actor": string, "claimedAt": string, "expiresAt": string|null, "revisionAtClaim": string }`.
`domain`, `assignee`, `claim`, and `externalIssue` are `null` when unset —
never an empty string or an omitted key.

### `card-list`

```json
{
  "schemaVersion": 1,
  "type": "card-list",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "count": 2,
  "cards": [ { "...": "card object, see above" } ]
}
```

### `verification-report`

```json
{
  "schemaVersion": 1,
  "type": "verification-report",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "isValid": false,
  "violations": [
    {
      "code": "missing-task-brief",
      "message": "Card ITPNG-124 is missing required field \"taskBrief\" for lane READY.",
      "severity": "error",
      "cardId": "ITPNG-124",
      "field": "taskBrief",
      "file": "todo/cards/ITPNG-124.md"
    }
  ]
}
```

`code` is one of the stable `ViolationCode` values (`src/Verification/ViolationCode.php`):
`duplicate-card-id`, `invalid-filename`, `invalid-project-prefix`,
`unsupported-lane`, `invalid-status-lane-mapping`, `missing-required-field`,
`missing-task-brief`, `invalid-timestamp`, `malformed-metadata`,
`duplicate-metadata-field`, `invalid-wip-count`, `invalid-claim`,
`invalid-transition-state`, `board-metadata-inconsistency`,
`stale-format-version`, `incompatible-format-version`, `archive-conflict`,
`source-directory-ambiguity`. `severity` is `error` or `warning`;
`isValid` is `true` iff there are zero `error`-severity violations.

### `mutation-result`

```json
{
  "schemaVersion": 1,
  "type": "mutation-result",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "operation": "move",
  "card": "ITPNG-123",
  "previousRevision": "aaaa...(64 hex)",
  "newRevision": "bbbb...(64 hex)",
  "dryRun": false,
  "warnings": [],
  "changedFields": ["lane"],
  "timestamp": "2026-07-12T09:00:00+00:00",
  "transition": {
    "previousLane": "READY",
    "newLane": "DOING",
    "previousRevision": "aaaa...(64 hex)",
    "newRevision": "bbbb...(64 hex)",
    "actor": "codex",
    "timestamp": "2026-07-12T09:00:00+00:00",
    "warnings": [],
    "changedFields": ["lane"]
  }
}
```

`transition` is present only for `card move` (and `card claim
--move-to-doing` when the move succeeds); otherwise it is `null`.
`previousRevision` is `null` for `create`. `card` is the card's ID string.

### `error` / `conflict-error`

```json
{
  "schemaVersion": 1,
  "type": "conflict-error",
  "generatedAt": "2026-07-12T09:00:00+00:00",
  "exception": "ConflictException",
  "message": "Card ITPNG-123 has revision bbbb..., expected aaaa....",
  "cardId": "ITPNG-123",
  "expectedRevision": "aaaa...(64 hex)",
  "actualRevision": "bbbb...(64 hex)"
}
```

The extra fields alongside `exception` and `message` depend on the
exception type (see `docs/php-api.md`'s exception table): `ValidationException`
adds `cardId`/`field`; `ConflictException` adds `cardId`/`expectedRevision`/
`actualRevision`; `NotFoundException` adds `cardId`. `message` has control
characters stripped; it is never a stack trace.

## PHP array-shape reference

Every method in `JsonBoardRenderer` carries a precise `@return` array-shape
annotation (verified by PHPStan at `max` level) that matches the JSON
examples above field-for-field; read `src/Rendering/JsonBoardRenderer.php`
directly if you need the authoritative, machine-checked type.
