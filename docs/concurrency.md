# Concurrency, transitions, and claims

This package has no server, no lock daemon, no database. Every guarantee
below comes from the filesystem plus one hash.

## Revisions (optimistic concurrency)

A `CardRevision` (`src/Domain/CardRevision.php`) is `hash('sha256', $bytes)`
of the exact bytes a mutation read from disk. It changes if and only if
those bytes change.

Every `CardMutationService` method accepts an optional `expectedRevision`.
If given and it does not match the card's current on-disk revision, the
method throws `ConflictException` (`cardId`, `expectedRevision`,
`actualRevision`) **before** touching the file. This is the same shape of
check `git` uses for a fast-forward: read, compute intent, write only if
nothing changed underneath you.

```php
$current = $repository->load($id);
$mutation->update($id, summary: 'New summary', expectedRevision: $current->revision);
```

Without `expectedRevision`, a mutation always applies to whatever is
currently on disk ("last write wins" — same as editing the file by hand
twice in a row).

## Atomic writes

`MarkdownCardRepository::atomicWrite()` never edits a file in place:

1. Write the full new content to a temporary sibling file
   (`.{name}.{random}.tmp` in the same directory, so it's on the same
   filesystem/volume as the target).
2. `fflush()` and close it.
3. `rename()` the temporary file over the target.

`rename()` on POSIX filesystems (and NTFS, for a same-volume rename) is
atomic: a concurrent reader always sees either the fully-old or the
fully-new content, never a partial write. If any step fails, the original
file is untouched and the temporary file is the only thing left behind (and
is cleaned up on a failed rename). The same pattern (`moveFile()`, using a
plain `rename()`, refusing to overwrite an existing destination) backs
`card archive` / `card restore`.

`atomicWrite()` and `moveFile()` both refuse to operate through a symlink
(`is_link()` check), so a card path can't be used to write outside the card
directory via a symlink swap.

## Transitions

`TransitionPolicy` (`src/Transition/TransitionPolicy.php`) validates a lane
move against `BoardConfig::$transitions` — see `docs/configuration.md` for
the default graph. Validation is a separate step from writing:
`CardMutationService::move()` calls `TransitionPolicy::validate()` (which
throws `ValidationException` listing the allowed targets) before it ever
opens the file for writing. A `TransitionResult` (nested inside the
`MutationResult` returned by `move()`) reports `previousLane`, `newLane`,
`previousRevision`, `newRevision`, `actor`, `timestamp`, `warnings`, and
`changedFields`.

Archiving is not a transition — it's allowed from any lane once
`archiveDirectory` is configured, independent of the transition graph.

## Claims

A `Claim` (`src/Domain/Claim.php`) records `actor`, `claimedAt`, an optional
`expiresAt`, and the `CardRevision` that was current when the claim was
made. Rules, enforced by `CardMutationService::claim()`:

- A **current, non-expired** claim by a *different* actor cannot be silently
  replaced — `claim()` throws `ConflictException`.
- A claim by the **same** actor is idempotent (re-claiming refreshes the
  claim).
- An **expired** claim (`expiresAt` in the past) may be replaced by anyone.
- `claim(..., moveToDoing: true)` additionally attempts to move the card
  from its current lane to `DOING`; if that transition isn't allowed by
  `TransitionPolicy`, the claim still succeeds but a warning is returned
  instead of a hard failure (the actor still holds the claim; the lane
  move just didn't happen).
- `release()` requires the caller to be the current claim holder —
  releasing someone else's claim throws `ConflictException`. Releasing a
  card with no claim throws `ValidationException`.

This is deterministic under concurrent local processes because the claim
check and the write happen against the same read of the file, and the write
itself is atomic: two processes racing to claim the same card will have one
succeed and one see a `ConflictException` (either because the file changed
underneath it, or because the claim was already taken), never a torn or
merged result.

## What this deliberately is not

No distributed lock, no scheduler, no heartbeat/lease renewal service, no
leader election, no server-side database. If you need cross-machine
coordination beyond "the same Git working copy, read and written by
processes that can see each other's filesystem," that belongs in a layer
above this package (e.g. `voku/agent-loop`'s session orchestration), not
inside it.
