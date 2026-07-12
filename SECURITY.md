# Security policy

## Reporting a vulnerability

Please open a private security advisory at the URL in this repository's
`composer.json` (`support.issues`), or contact the maintainer directly
rather than filing a public issue, if the report includes exploit details.

## What this package does to stay safe by default

- **Path traversal**: card IDs are validated against a strict
  `PREFIX-NUMBER` pattern (`Domain\CardId`) before ever being used to build
  a filesystem path; lane and status values are similarly validated
  (`Domain\Lane`, `Domain\CardStatus`). No user-supplied string is
  concatenated into a path without going through one of these validators
  first.
- **NUL bytes**: rejected explicitly in `CardId`, `Lane`, `CardStatus`, and
  card file content (`CardParser`), not relied upon to be filtered by the
  OS or a later `fopen()` call.
- **Symlinks**: `MarkdownCardRepository::atomicWrite()`, `readRaw()`,
  `deleteFile()`, and `moveFile()` all check `is_link()` and refuse to
  follow a symlink at the target path, so a card path can't be used to
  write outside the intended directory via a symlink swap.
- **Atomic writes**: every mutation writes to a temporary sibling file and
  `rename()`s it over the target — never edits a file in place — so a
  crash or a concurrent read never observes a partially-written card. See
  `docs/concurrency.md`.
- **No error suppression operator**: this codebase does not use `@` to
  silence errors; failures are contextual exceptions
  (`voku\AgentKanban\Exception\*`), not swallowed.
- **No silent recovery from corrupted card files**: a malformed card is
  either a hard `ValidationException` (strict load paths:
  `MarkdownCardRepository::loadAll()`, mutations) or a structured
  `Violation` your code has to explicitly inspect (lenient load path:
  `loadAllLenient()` + `BoardVerifier`) — never silently skipped or
  auto-repaired.
- **Permissions**: this package never widens file permissions; it does not
  set custom modes on the files/directories it creates beyond what your
  process's umask already implies for a normal `mkdir()`/file write.
- **No credential storage**: `ExternalIssueProvider` implementations
  (Jira or otherwise) are entirely host-provided; this package never reads,
  stores, or transmits API tokens or other credentials. See
  `docs/external-issues.md`.
- **Contextual, non-leaking error messages**: exceptions distinguish
  validation, conflict, I/O, configuration, and external-provider failures
  (`docs/php-api.md`'s exception table) and the CLI's `--format=json` error
  output never includes a stack trace or file-system internals beyond the
  documented fields (`docs/json-format.md`).
- **Control-character sanitization**: CLI error messages have control
  characters stripped before being written to STDERR or embedded in JSON,
  so a card field can't be used to inject terminal escape sequences into a
  human's terminal.

## Supported versions

This project is pre-1.0; only the latest commit on the default branch
receives security fixes until a 1.0.0 stability policy is published.
