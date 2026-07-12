# Card format specification (format version 1)

A card is one UTF-8 Markdown file, one file per task. This document is the
normative specification for that file's structure. `CardParser` and
`CardSerializer` (`src/Repository/`) implement exactly this spec; the fixtures
in `tests/fixtures/` and `tests/Compatibility/` are golden files that pin it in
place.

## File location

- Preferred: `todo/cards/<PREFIX>-<NUMBER>.md`
- Legacy (still read, never required): `todo/jira/<PREFIX>-<NUMBER>.md`
- If both directories exist, `todo/cards/` wins in full (legacy files under
  `todo/jira/` are ignored while `todo/cards/` exists) — see
  `docs/configuration.md` for the resolution rule and its rationale.
- `<PREFIX>` is an uppercase alphanumeric identifier starting with a letter
  (e.g. `ITPNG`, `ABC`). `<NUMBER>` is a positive integer with no leading
  zeros in the canonical form written by `CardSerializer`; a leading-zero
  filename is still read for compatibility.

## Structure

```markdown
# ITPNG-123: Implement secure form validation

- **Ticket:** ITPNG-123
- **Lane:** READY
- **Status:** Selected
- **Domain:** Security
- **Assignee:** Lars Moelleken
- **Created:** 2026-06-01T09:00:00+00:00
- **Updated:** 2026-06-09T11:32:00+00:00
- **Summary:** Short summary of the work.
- **Next:** Write unit tests for the validator.
- **Validation:** Run make test_unit.
- **Priority:** 1
- **Wave:** Wave 1
- **Claim:** codex|claimed=2026-06-09T11:00:00+00:00|expires=-|rev=3f2504e...
- **External issue:** jira:ITPNG-123
- **Format version:** 1

## Handoff / Context
Additional context notes for whoever picks this card up next.

## Agent Task Brief
#### ITPNG-123: Implement secure form validation
Details about the task an agent needs before starting.
```

Every element below the H1 title is optional except `Ticket` and `Lane`; a
card missing both can still be parsed if the filename supplies the ticket ID,
but a card with no resolvable lane is a parse error (see "Invalid input"
below).

### Title (H1)

`# <ID>: <Title>`. If absent, the title defaults to the card ID.

### Metadata bullets

One field per line: `- **<Label>:** <value>`. Recognized labels, in the exact
order `CardSerializer` writes them:

| Label | Card property | Notes |
| --- | --- | --- |
| `Ticket` | `id` | Falls back to the filename (`<PREFIX>-<NUMBER>.md`) when absent. |
| `Lane` | `lane` | Required (directly or inferred — in practice always explicit). Uppercased. |
| `Status` | `status` | Free text; whether it is valid for the lane is a `BoardConfig::$statusToLane` / verifier concern, not a parser concern. |
| `Domain` | `domain` | Free text label / category. |
| `Assignee` | `assignee` | Free text. |
| `Created` | `createdAt` / `createdAtRaw` | See "Timestamps" below. |
| `Updated` | `updatedAt` / `updatedAtRaw` | See "Timestamps" below. |
| `Summary` | `summary` | Free text. |
| `Next` | `nextAction` | Free text. |
| `Validation` | `validation` | Free text (e.g. a command to run). |
| `Priority` | `priority` | Integer. Legacy label `Next pull rank` is also read (only consulted when `Priority` is absent); `CardSerializer` always writes `Priority`. Only a value `> 0` counts as an active next-pull candidate (see `BoardQueryService::nextPullCandidates()`); `0` or negative is still stored but never surfaced as a candidate. |
| `Wave` | `wave` | Free text execution-wave / grouping label. |
| `Claim` | `claim` | See "Claim encoding" below. |
| `External issue` | `externalIssue` | `<system>:<key>`, e.g. `jira:ITPNG-123`. |
| `Format version` | `formatVersion` | Integer. Defaults to `1` when absent. |

Any other `- **Label:** value` line is an **extension field**: preserved
verbatim, keyed by its exact label, and re-emitted by `CardSerializer` after
the recognized fields, in the order first seen. This is how the legacy `Fit`
field from 0.x boards round-trips without loss even though it has no
first-class property in the 1.0 model.

### Sections

- `## Handoff / Context` — free-form Markdown body -> `handoffNotes`.
- `## Agent Task Brief` — free-form Markdown body -> `taskBrief`. If this
  heading is absent but the body contains a `#### <ID>: ...` heading (the 0.x
  inline-brief convention), that heading and everything until the next `##`
  section is read as the brief for backward compatibility.
- Any other `##` section is preserved verbatim as a trailing block
  (`Card::$extraSectionsRaw`) and re-emitted unchanged after the recognized
  sections. Nothing below the title is ever silently dropped.

### Claim encoding

```text
- **Claim:** <actor>|claimed=<ISO-8601>|expires=<ISO-8601 or "-">|rev=<64-hex-char SHA-256>
```

`rev` is the `CardRevision` (see `docs/concurrency.md`) that was current on
the card at the moment of claiming, so a claim can be checked for staleness
independent of the file's current revision.

### External issue encoding

```text
- **External issue:** <system>:<key>
```

`<system>` is a free-form lowercase-by-convention identifier (`jira`, ...);
the generic engine never interprets it. See `docs/external-issues.md`.

## Timestamps

Recognized on read, tried in this order:

1. ISO-8601 / RFC 3339 with offset (`Y-m-d\TH:i:sP`)
2. `Y-m-d H:i:s`
3. `Y-m-d`
4. `d.m.Y H:i:s` (legacy, seen in existing 0.x fixtures)
5. `d.m.Y`

If none match, the parsed `DateTimeImmutable` is `null` but the **raw text is
preserved unchanged** (`createdAtRaw` / `updatedAtRaw`) — an unparseable
timestamp is a verifier violation (`invalid-timestamp`), never a silent data
loss and never a hard parse failure.

`CardSerializer` always writes timestamps in ISO-8601 with offset
(`Y-m-d\TH:i:sP`) when a value parsed successfully. If a raw value exists but
never parsed, the serializer writes the original raw text back unchanged —
mutating a card never silently reformats a field the engine could not
understand.

## Determinism, newlines, escaping

- All line endings are normalized to `\n` on read (`\r\n` and `\r` are
  accepted on input, never on output).
- `CardSerializer` output always ends with exactly one trailing `\n`.
- Field order is fixed (the table above); this makes diffs of card files
  small and predictable across every write path (CLI, `CardMutationService`,
  future tooling).
- Bullet field values are single-line. Any `\n` inside a value is collapsed to
  a single space before serialization; internal whitespace runs are not
  otherwise altered.
- No character escaping is required inside a value beyond the newline
  collapse above — bullet values are not interpreted as Markdown table cells
  (that escaping only applies to the *rendered* board, see
  `docs/json-format.md` and `BoardRenderer`).

## Invalid input

| Condition | Behavior |
| --- | --- |
| Missing `Ticket` and un-parseable filename | `ValidationException` (card cannot be identified). |
| Missing or malformed `Lane` | `ValidationException`. |
| A bullet label repeated more than once in the same file | `ValidationException` ("duplicate metadata field"). Cards must define each field at most once. |
| Unparseable `Created` / `Updated` | Not a parse error; raw text preserved, `null` parsed value, flagged by `BoardVerifier` as `invalid-timestamp`. |
| Malformed `Claim` (wrong shape, bad revision hex) | `ValidationException`. |
| Malformed `External issue` (missing `:`) | `ValidationException`. |
| Non-numeric `Priority` / `Format version` | `ValidationException`. |

`MarkdownCardRepository::loadAllLenient()` (used by `agent-kanban verify`)
catches these per file and turns them into structured `Violation`s so one
malformed card never hides problems in the rest of the board.
`MarkdownCardRepository::loadAll()` (used by rendering, queries, mutations)
throws on the first bad card — those code paths assume a valid board.

## Backward compatibility with 0.x cards

Every field used by 0.1.0 cards (`Ticket`, `Lane`, `Status`, `Domain`,
`Assignee`, `Updated`, `Fit`, `Summary`, `Next`, `Validation`, `Wave`,
`Next pull rank`) still reads correctly:

- `Fit` has no first-class 1.0 property and round-trips as an extension
  field.
- `Next pull rank` is read as a fallback for `Priority`.
- The legacy `dd.mm.YYYY[ HH:MM:SS]` timestamp format for `Updated` still
  parses.
- `todo/jira/` cards without an explicit `## Agent Task Brief` heading still
  have their `#### <ID>: ...` inline brief recognized.

No 0.x card is ever rewritten as a side effect of being read. Reformatting
(e.g. canonical timestamp format, canonical field order) only happens when a
card is explicitly written by a mutation — see `UPGRADING.md`.
