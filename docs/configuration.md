# Board configuration

Everything host-specific lives in `voku\AgentKanban\Config\BoardConfig`
(`src/Config/BoardConfig.php`). The engine itself has no hard-coded project
prefix, status vocabulary, WIP limit, or lane set — those are all
`BoardConfig` fields with sensible defaults matching the workflow this
package originally shipped with.

## Fields

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `projectPrefix` | `string` | *(required)* | Uppercase alphanumeric identifier, e.g. `ITPNG`. |
| `cardDirectory` | `string` | `todo/cards` | Preferred card directory. |
| `legacyCardDirectory` | `string` | `todo/jira` | Legacy card directory, still read for compatibility. |
| `archiveDirectory` | `?string` | `null` | Where `card archive` moves a card file. Archiving is disabled until this is set. |
| `lanes` | `list<string>` | `['BACKLOG','READY','DOING','VERIFY','BLOCKED']` | The board's lanes, in no particular order. |
| `statusToLane` | `array<string, list<string>>` | `[]` | Optional per-lane allow-list of status strings. Empty means "no restriction" for every lane; a lane present in this map restricts cards in that lane to the listed statuses. |
| `wipLimits` | `array<string, int>` | `[]` | Keyed by a lane name, or a comma-joined set of lane names to cap their *combined* total, e.g. `"READY,DOING,VERIFY" => 3`. |
| `requiredFieldsByLane` | `array<string, list<string>>` | `['READY' => ['taskBrief']]` (only if `READY` is one of `lanes`) | Field names are `Card` property names: `summary`, `taskBrief`, `assignee`, `domain`, `nextAction`, `validation`, `wave`. |
| `transitions` | `array<string, list<string>>` | see below | Allowed lane-to-lane moves. |
| `formatVersion` | `int` | `1` | The card format version this board expects; see `docs/card-format.md`. |
| `externalIssueSystem` | `?string` | `null` | A label for which external tracker this board compares against (see `docs/external-issues.md`); the engine never interprets it. |

`cardDirectory`, `legacyCardDirectory`, and `archiveDirectory` (when set)
must be non-empty, repository-relative paths: no leading `/` or Windows
drive letter, no `\`, no NUL byte, and no `.`/`..`/empty path component
(e.g. `todo//cards` or `todo/../cards`). `BoardConfig`'s constructor throws
`ConfigurationException` immediately for any of these — a malformed or
malicious `kanban.config.json` cannot point the repository at a directory
outside the board root.

### Default transitions

```
BACKLOG -> READY
READY   -> DOING, BLOCKED
DOING   -> VERIFY, BLOCKED
VERIFY  -> DOING
BLOCKED -> READY, DOING, BACKLOG
```

This default only applies when `lanes` is exactly the default five-lane set.
If you customize `lanes` without also supplying `transitions`, you get an
**empty** transition map (every move must then be configured explicitly) —
this avoids silently validating moves against lanes that may not even exist
on your board.

Archiving (`card archive` / `card restore`) is **not** a lane transition; it
is its own mutation, allowed from any lane, gated only by whether
`archiveDirectory` is configured.

## Sensible defaults, not hard-coded policy

Compare this to the pre-1.0 engine, which hard-coded:

- The literal project prefix `ITPNG` as a fallback.
- German Jira status names (`Selected`, `In Planung`, `Warten`, `Fertig`) as
  the *only* recognized statuses.
- A WIP limit of `3` with no way to change it.
- References to `MEMORY.md`, `make memory_review`, and Docker validation
  commands baked into the rendered board text.

None of that exists in the engine anymore. If your board needs any of it,
put it in your own `BoardConfig` (below) or your own `AGENTS.md` /
`TODO.md` — never in this package.

## Loading a config

```php
use voku\AgentKanban\Config\BoardConfig;

// Defaults, only the prefix required:
$config = BoardConfig::default('ITPNG');

// Fully custom:
$config = new BoardConfig(
    projectPrefix: 'ITPNG',
    archiveDirectory: 'todo/archive',
    wipLimits: ['READY,DOING,VERIFY' => 3],
    requiredFieldsByLane: ['READY' => ['taskBrief', 'assignee']],
);

// From a JSON file:
$config = BoardConfig::fromJsonFile('todo/kanban.config.json');
```

Example `todo/kanban.config.json` (matching the old repository's actual
policy, expressed as host configuration instead of engine code):

```json
{
  "projectPrefix": "ITPNG",
  "statusToLane": {
    "READY": ["Selected", "In Planung"],
    "DOING": ["In Progress"],
    "VERIFY": ["In Test"],
    "BLOCKED": ["Warten"],
    "BACKLOG": ["Backlog"]
  },
  "wipLimits": {
    "READY,DOING,VERIFY": 3
  },
  "requiredFieldsByLane": {
    "READY": ["taskBrief"]
  }
}
```

The CLI (`bin/agent-kanban`) resolves configuration in this order:

1. `--config=<path>` if given.
2. `todo/kanban.config.json` in the board root, if it exists.
3. `todo/board.md`'s `- **Project prefix:** X` bullet, using all other
   defaults.
4. The prefix implied by whatever card files already exist (first match,
   sorted), using all other defaults.

If none of those resolve a prefix, the CLI fails with a `ConfigurationException`
(exit code 5) rather than silently defaulting to a hard-coded value.

## Directory resolution

`todo/cards/` is preferred; `todo/jira/` is read for compatibility. If both
exist, `todo/cards/` wins **in full** — `todo/jira/` is ignored, not merged.
`agent-kanban verify` reports this as a `source-directory-ambiguity` warning
so you notice and can migrate fully. See `docs/card-format.md` for the file
naming convention within whichever directory is active.
