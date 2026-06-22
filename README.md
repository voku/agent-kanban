# Coding Agent | Kanban via PHP

A lightweight, strict PHP library designed to parse, render, verify, and synchronize Markdown-based Kanban boards tailored for coding-agent workflows.

We provide here a CLI utility and programmatic API to handle split-file Kanban setups.

---

## Features

- **Split-File Board Management**: Aggregates a directory of individual Markdown cards (`todo/cards/ITPNG-*.md`) and a metadata file (`todo/board.md`) into a single consolidated board markdown document. Local boards work fully offline; `todo/jira/ITPNG-*.md` is also still supported.
- **Strict Verifier (`TodoBoardVerifier`)**: Validates the integrity of the board against a set of robust constraints (WIP limits, status mappings, ticket uniqueness, task briefs, matching counts).
- **Flexible Rendering (`TodoBoardCli render`)**: Outputs a clean Markdown board representation with query options to filter by lane, assignee, domain, status, search string, and limit.
- **Jira Synchronization (`TodoBoardCli jira-sync`)**: Syncs local card metadata with remote Jira issue states.
- **Zero-Dependency Core Helpers**: Uses package-local, byte-safe string utilities to run reliably in generic PHP environments.

---

## Directory Structure

```
vendor/
├── bin/
│   └── agent-kanban             # Standalone CLI binary
├── src/
│   ├── Composer/                # Composer integration
│   ├── JiraIssueProvider.php    # Interface for remote Jira integration
│   ├── TodoBoardCard.php        # Immutable card representation
│   ├── TodoBoardCli.php         # CLI command router and output formatter
│   ├── TodoBoardRenderOptions.php # Value object for render filtering
│   ├── TodoBoardSource.php      # Board assembler & markdown parser
│   └── TodoBoardVerifier.php    # Integrity checking engine
├── tests/
│   ├── fixtures/                # Mock project structures for testing
│   └── *Test.php                # Package test cases
├── composer.json                # Composer package config
├── phpstan.neon.dist            # Static analysis configuration
└── phpunit.xml                  # PHPUnit test runner settings
```

---

## The Markdown Kanban Architecture

The board operates on a split-file architecture to avoid git conflicts during concurrent agent execution. Cards are plain Markdown files and work offline; Jira sync (below) is optional and requires a host-provided `JiraIssueProvider`.

`todo/cards/` is the preferred local card directory. `todo/jira/` is also supported, so existing boards keep working without migration. If both directories exist, `todo/cards/` takes precedence.

### 1. Board Metadata (`todo/board.md`)
Maintains board-wide variables:
```markdown
# Board Metadata

- **Source:** `todo/cards/*.md`
- **Done count:** 301
```

### 2. Card Source Files (`todo/cards/ITPNG-*.md`)
Each ticket has its own Markdown file with frontmatter metadata:
```markdown
# ITPNG-123: Implement secure form validation

- **Ticket:** ITPNG-123
- **Lane:** READY
- **Status:** Selected
- **Domain:** Security
- **Assignee:** Lars Moelleken
- **Updated:** 2026-06-09 11:32:00
- **Fit:** (Recommended) Task has clear inputs and target files.
- **Summary:** Short summary of the work.
- **Next:** Write unit tests for the validator.
- **Validation:** Run make test_unit.
- **Wave:** Wave 1
- **Next pull rank:** 1

## Handoff / Context
Additional context notes go here...
```

---

## CLI Usage

Run the package binary from the project root directory:

```bash
./vendor/bin/agent-kanban <command> [options]
```

### Supported Commands

* **`summary`**
  Prints an overview of lane sizes, WIP health, and status counters:
  ```bash
  ./vendor/bin/agent-kanban summary
  ```

* **`render`**
  Renders the board representation. Supports optional filters:
  ```bash
  ./vendor/bin/agent-kanban render --lanes=READY,DOING --assignee=moellekenl --limit=5
  ```
  *Available filters:* `--lanes`, `--domain`, `--assignee`, `--status`, `--search`, `--limit`.

* **`lane <LANE>`**
  Prints the tickets and details for a specific lane (e.g. `READY`, `DOING`, `VERIFY`, `BLOCKED`, `BACKLOG`):
  ```bash
  ./vendor/bin/agent-kanban lane READY
  ```

* **`next-pull`**
  Shows recommended cards ready to be pulled by agents.

* **`ticket <TICKET_KEY>`** (or `context <TICKET_KEY>`)
  Renders the compiled context and brief for a specific ticket:
  ```bash
  ./vendor/bin/agent-kanban ticket ITPNG-123
  ```

* **`brief <TICKET_KEY>`**
  Extracts and displays only the *Agent Task Brief* section of the card.

* **`jira-sync`**
  Syncs local Markdown card statuses against the Jira API using the provided issue provider interface.

---

## Board Verification Rules

The `TodoBoardVerifier` executes the following checks:
1. **Entrypoint Integrity**: `TODO.md` in the workspace root must point to the active card directory (`todo/cards/`, or `todo/jira/` if that's what the project uses) and must not contain raw lane tables directly.
2. **Required Sections**: The compiled board must contain sections like `# TODO for Coding Agents`, `## ITPNG Markdown Board`, `### WIP Health`, etc.
3. **Count Verification**: Lane headers (e.g. `#### READY (Count: 3)`) must match the actual number of files in that lane.
4. **Valid Status Mapping**:
   - `READY` $\rightarrow$ `Selected`, `In Planung`
   - `DOING` $\rightarrow$ `In Progress`
   - `VERIFY` $\rightarrow$ `In Test`
   - `BLOCKED` $\rightarrow$ `Warten`
   - `BACKLOG` $\rightarrow$ `Backlog`
5. **No Duplicates**: A ticket key can only exist in a single lane.
6. **Task Brief Existence**: All cards in the `READY` lane must include an Agent Task Brief.
7. **WIP Constraints**: Validates that active WIP fits within the limit (maximum `3` active implementation cards).

---

## Development

Run development commands within the `vendor/` directory:

```bash
# Install package dependencies
composer install

# Run static analysis (PHPStan)
composer phpstan

# Run unit tests
composer test

# Run all CI verification checks
composer ci
```
