# Contributing

Thanks for considering a contribution to `voku/agent-kanban`.

## Scope first

Before writing code, check `README.md`'s "What this is not" section and
`docs/PLAN.md`. This package owns card parsing/serialization, board config,
queries, rendering, verification, transitions, safe mutations, the CLI, and
optional external-issue comparison. It does not own agent execution, LLM
APIs, terminal streaming, any UI, Git worktree orchestration, PR creation,
or session/memory/workflow governance — that belongs in `voku/agent-loop`.
A PR that grows this package toward being an agent-execution platform or a
generic Kanban UI will likely be declined regardless of code quality; ask
first (open an issue) if you're unsure whether something fits.

## Development setup

```bash
git clone https://github.com/voku/agent-kanban.git
cd agent-kanban
composer install
```

## Before opening a PR

```bash
composer test      # PHPUnit
composer phpstan    # PHPStan at max level
composer cs-check   # php-cs-fixer, dry-run
composer ci          # all of the above, plus composer validate --strict
```

All four must pass. `composer cs-fix` applies formatting fixes
automatically.

## Code style

- `declare(strict_types=1)` in every file.
- `final` classes by default; avoid inheritance unless there's a
  demonstrated need. No traits.
- Value objects are `readonly`.
- No `mixed` where a precise type or a PHPDoc generic/array-shape can
  express the contract instead — this codebase runs PHPStan at `max`
  level with **no** `@phpstan-ignore` comments; if you hit one, fix the
  underlying type instead of suppressing it (a maintainer will ask you to
  redo it otherwise).
- No generic `Manager`/`Helper`/`Utility` classes. If you need shared
  logic, give it a name that describes what it actually does.
- Comments explain *why*, not *what* — only when the reason isn't obvious
  from the code itself.
- Prefer editing existing files over adding new abstractions for a single
  use site.

## Tests

- Put unit tests next to the layer they test, mirroring `src/`'s directory
  structure under `tests/` (e.g. `src/Domain/CardId.php` →
  `tests/Domain/CardIdTest.php`).
- Use `#[DataProvider('...')]` attributes, not `@dataProvider` doc-comments
  (PHPUnit 12 removes doc-comment metadata support).
- Filesystem-touching tests must clean up their own temp directories (see
  the `#[After]` cleanup pattern in `tests/Mutation/CardMutationServiceTest.php`
  or `tests/Repository/MarkdownCardRepositoryTest.php` for the pattern to
  copy).
- If you change the card format (`docs/card-format.md`) or the JSON shapes
  (`docs/json-format.md`), update the doc in the same PR — these are meant
  to be normative, not aspirational.
- Existing 0.x card *fixtures* (`tests/fixtures/`) must keep parsing
  unmodified through `MarkdownCardRepository`/`CardParser` — the on-disk
  format is still backward compatible even though the pre-1.0
  `TodoBoard*` classes are not (see `UPGRADING.md`). If a change requires
  editing those fixtures or breaks how they parse, it's very likely a
  breaking change that needs a `CHANGELOG.md` / `UPGRADING.md` entry, not a
  quiet fixture edit.

## Breaking changes

Breaking changes are allowed pre-1.0 but must be:

1. Justified (what problem does it solve that a non-breaking change
   couldn't).
2. Documented in `CHANGELOG.md` and, if it affects an upgrade path,
   `UPGRADING.md`.
3. Never a silent rewrite of existing card files — a format change must be
   explicit, reviewable, and opt-in.

## Commit messages / PRs

Describe *why*, not just *what* — the diff already shows what changed.
Keep PRs focused; a bug fix doesn't need an accompanying refactor.
