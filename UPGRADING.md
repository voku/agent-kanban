# Upgrading

## From 0.1.x

**This is a breaking release.** `agent-kanban` has one known consumer
(`voku/agent-loop`), so rather than carry the old generated-Markdown
architecture forward as deprecated facades, the pre-1.0 PHP classes and CLI
commands built on it were removed outright in favor of the typed engine.
Everything below is a direct, mechanical replacement — there is no gradual
deprecation window for these classes.

What is **not** breaking: the on-disk board format. `todo/cards/` and
`todo/jira/` are both still read exactly as before, existing card files
(bullet-metadata Markdown, including the 0.x-only `Fit` field and legacy
`Next pull rank` field, and the legacy `dd.mm.YYYY[ HH:MM:SS]` timestamp
format) parse unchanged, and no card file is ever rewritten as a side effect
of reading it. If you only ever interacted with boards through card files on
disk, you have nothing to change.

### Removed PHP classes and their replacements

| Removed (0.1.x) | Replacement |
| --- | --- |
| `voku\AgentKanban\TodoBoardSource` | `voku\AgentKanban\Repository\MarkdownCardRepository` (reading cards) + `voku\AgentKanban\Rendering\BoardRenderer` (rendering). See `docs/php-api.md`. |
| `voku\AgentKanban\TodoBoardVerifier` | `voku\AgentKanban\Verification\BoardVerifier`, given a `Board` built from `MarkdownCardRepository`. Returns a structured `VerificationReport`, not a `run(): int` that prints to STDOUT/STDERR. |
| `voku\AgentKanban\TodoBoardCli` | `voku\AgentKanban\Cli\CliApplication` (what `vendor/bin/agent-kanban` now runs). |
| `voku\AgentKanban\TodoBoardCard` | `voku\AgentKanban\Domain\Card` (far more fields; see `docs/card-format.md`). |
| `voku\AgentKanban\TodoBoardRenderOptions` | `voku\AgentKanban\Rendering\RenderOptions` (same shape: lanes/domain/assignee/status/search/limit). |
| `voku\AgentKanban\JiraIssueProvider` | `voku\AgentKanban\ExternalIssue\ExternalIssueProvider` (generic, not Jira-specific — see `docs/external-issues.md`). |

#### Reading a board

```php
// 0.1.x
$source = new TodoBoardSource($rootPath, $projectPrefix);
$markdown = $source->readBoardMarkdown();

// 1.0
$config = BoardConfig::default($projectPrefix);
$repository = new MarkdownCardRepository($rootPath, $config);
$board = new Board($config, $repository->loadAll(), $repository->resolveCardDirectory());
$markdown = (new BoardRenderer())->renderFull($board); // only if you actually need Markdown text
```

Prefer working with `$board` directly (`BoardQueryService`, `BoardVerifier`)
over rendering to Markdown and re-parsing it — that reparsing step is
exactly the pattern 1.0 removes. See `docs/architecture.md`.

#### Verifying a board

```php
// 0.1.x
$verifier = new TodoBoardVerifier($rootPath, $projectPrefix);
$exitCode = $verifier->run(); // printed to STDOUT/STDERR itself

// 1.0
$report = (new BoardVerifier())->verify($board);
if (!$report->isValid()) {
    foreach ($report->errors() as $violation) {
        // $violation->code, ->message, ->severity, ->cardId, ->field, ->file
    }
}
```

The old verifier also enforced several hard-coded, project-specific rules
that no longer exist anywhere in the engine: exact German Jira status names
per lane, a fixed WIP limit of `3`, and a requirement that a large
generated Markdown document contain specific section headings (an artifact
of the old "reparse the generated board" architecture). If your project
relied on those *specific* rules, reproduce them as your own `BoardConfig`
(`docs/configuration.md`) — e.g. `statusToLane` for the status vocabulary,
`wipLimits` for the WIP cap.

#### Using the CLI

`vendor/bin/agent-kanban` now runs `CliApplication`, not `TodoBoardCli`.
Command mapping:

| 0.x command | 1.0 equivalent | Notes |
| --- | --- | --- |
| `summary` | `summary` | Output is generic now — no project-specific policy prose. |
| `render [filters]` | `render [filters]` | Same filter flags (`--lanes`, `--domain`, `--assignee`, `--status`, `--search`, `--limit`); output is a generic Markdown render, not the old board document. |
| `lane <LANE>` | `lane <LANE>` | Now also validates the lane is one of the board's configured lanes. |
| `next-pull` | `next-pull` | Same semantics: priority `> 0`, ascending. |
| `ticket <ID>` / `context <ID>` | `card show <ID>` | Removed; use `card show`. |
| `brief <ID>` | `card show <ID>` (includes the task brief) | Removed as a standalone command. |
| `jira-sync [--jql=...]` | `external-sync --provider-class=<FQCN> [--query=...]` | Removed; `external-sync` requires `--provider-class` pointing at your own `ExternalIssueProvider` implementation (see `docs/external-issues.md`) instead of a `JiraIssueProvider` constructor argument. `--query` replaces `--jql`. |
| *(none)* | `verify`, `card create/update/move/claim/release/archive/restore` | New. See `docs/cli.md`. |

### New card fields are optional, additive

`Created`, `Priority` (with `Next pull rank` still read as a fallback),
`Claim`, `External issue`, and `Format version` are all new, optional bullet
fields. Existing cards without them parse exactly as before (defaults:
`createdAt` null, `priority` null, `claim` null, `externalIssue` null,
`formatVersion` 1). See `docs/card-format.md`.

### Timestamps are canonicalized on write, never on read

Reading a card with the legacy `dd.mm.YYYY[ HH:MM:SS]` timestamp format
still works and never rewrites the file. If you (or a coding agent) later
*update* that same card through `CardMutationService` / the CLI, the
timestamp fields you touch are rewritten in canonical ISO-8601
(`Y-m-d\TH:i:sP`) as part of that explicit write — this is the serializer's
normal, deterministic output format, not a silent bulk migration. Running
`agent-kanban verify` or any read-only command never writes anything.

### Recommended follow-up (optional, not required)

- Add a `todo/kanban.config.json` (or `- **Project prefix:**` in
  `todo/board.md`) instead of relying on prefix inference from existing
  filenames, especially for a brand-new board with no cards yet.
- If you use Jira sync, write a small `ExternalIssueProvider` adapter (see
  `docs/external-issues.md`) and switch your automation to
  `external-sync --provider-class=...`.

## Format migrations

There is no format migration in this release: the on-disk card format is
unchanged (still bullet-metadata Markdown, not YAML frontmatter — see
`docs/card-format.md`'s rationale). If a future release ever changes the
on-disk format, it will be explicit, reviewable, and opt-in — never a
silent rewrite triggered by reading an old file.
