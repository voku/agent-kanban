# Troubleshooting

## "Could not determine the project prefix"

`ConfigurationException`, CLI exit code `5`. The CLI could not resolve a
project prefix from `--config`, `todo/kanban.config.json`,
`todo/board.md`'s `Project prefix` bullet, or any existing card filename.
Fix: add one of those, or pass `--config=<path>` / construct
`BoardConfig` explicitly in your own script. See `docs/configuration.md`.

## "Card X already exists" on `card create`

`ConflictException`, exit code `3`. A file for that ID already exists in
either `todo/cards/` or `todo/jira/`. Use `card update` instead, or `card
show <ID>` to inspect the existing card.

## "Card X has revision ..., expected ..." on any mutation

`ConflictException`, exit code `3`. You passed `--expected-revision` and the
file changed since you last read it (someone else edited it, or you're
looking at a stale copy). Re-read the card (`card show <ID>` /
`--format=json` to get the current `revision`) and retry with the fresh
value, or omit `--expected-revision` if last-write-wins is acceptable here.

## "Cannot move from X to Y. Allowed targets from X: ..."

`ValidationException`, exit code `1`. The move isn't in
`BoardConfig::$transitions`. Either move to an allowed lane, or add the
transition to your board config — see `docs/configuration.md`. Remember:
if you customized `lanes` without customizing `transitions`, the default is
an *empty* transition map, not the five-lane default.

## "Card X is already claimed by ..."

`ConflictException`, exit code `3`, from `card claim`. Someone else holds a
current, non-expired claim. Either wait/coordinate with them, or if the
claim is stale and you control the claiming policy, have the original actor
release it, or configure a shorter `--expires` window on future claims so
stale ones age out. See `docs/concurrency.md`.

## `agent-kanban verify` fails with `source-directory-ambiguity`

This is a **warning**, not an error (`isValid()` stays `true` for warnings
alone) — it fires when both `todo/cards/` and `todo/jira/` exist.
`todo/cards/` wins in full; `todo/jira/` is ignored. Migrate remaining cards
out of `todo/jira/` and remove the directory to clear the warning, at your
own pace.

## `agent-kanban verify` fails with `board-metadata-inconsistency`

Either `todo/board.md`'s `Project prefix` doesn't match your configured
`projectPrefix`, or `TODO.md` doesn't reference the active card directory
(`todo/cards/` or `todo/jira/`, whichever is resolved). Fix whichever file
is out of date — see `docs/card-format.md` for `todo/board.md`'s format.

## A card file fails to parse ("Duplicate metadata field ...", "Invalid card ID ...")

These come from `CardParser` / `MarkdownCardRepository::loadAllLenient()`
and show up as `malformed-metadata` / `duplicate-metadata-field` /
`duplicate-card-id` violations from `agent-kanban verify`, rather than
crashing the whole board load. Fix the specific file named in the
violation's `file` field. See `docs/card-format.md` for the exact format
and its "Invalid input" table.

## PHPStan / php-cs-fixer / phpunit can't install in a restricted sandbox

If you're running this repository's own tooling inside a network-restricted
environment: `phpstan/phpstan` is distributed by Packagist as a **dist-only**
package (its own `composer.json` blanks out its `source` field), so it can
only be installed via a GitHub API zipball download — if that specific
endpoint is blocked by your network policy while plain `git clone` isn't,
`composer install --prefer-source` still fails for this one package even
though every other dependency in this project (`phpunit/phpunit`,
`friendsofphp/php-cs-fixer`, ...) installs fine via git. This is a property
of how `phpstan/phpstan` publishes itself, not of this repository. A normal
developer machine or a GitHub Actions runner (both with unrestricted GitHub
access) install it the same way as any other dependency.

## `agent-kanban` CLI shows PHP warnings / notices

This should never happen — please open an issue with the exact command and
PHP version. The CLI is expected to run warning-free (see `docs/cli.md`'s
exit-code table and `SECURITY.md`'s "no error suppression operator" rule);
a stray warning indicates a bug in this package, not expected behavior.

## Still stuck?

Check `docs/architecture.md` for how the pieces fit together, or open an
issue at the URL in `composer.json`'s `support.issues`.
