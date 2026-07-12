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

## Atomic, lock-serialized writes

`MarkdownCardRepository::atomicWrite()` and `moveFile()` never edit a file
in place, and never let the expected-revision check race a concurrent
writer:

1. Open (creating if needed) a per-card lock file (`.{name}.lock`, next to
   the card file) and acquire an exclusive `flock()` on it. This serializes
   every `atomicWrite()`/`moveFile()` call against the same card file
   across processes on the same machine.
2. With the lock held: re-check the path isn't a symlink, check
   `mustNotExist`/destination-must-not-exist where applicable, then check
   the caller's `expectedRevision` (if given) against a fresh read of the
   file. Because this happens *after* the lock is acquired, no other
   process using this repository's API can change the file between the
   revision check and the write that follows.
3. Write the full new content to a temporary sibling file
   (`.{name}.{random}.tmp`, same directory as the target, so it's on the
   same filesystem/volume), handling partial `fwrite()` returns by looping
   until every byte is written, then `fflush()` and close it.
4. `rename()` the temporary file over the target (or, for `moveFile()`,
   `rename()` the card file itself to its destination).
5. Release the lock and remove the lock file.

`rename()` on POSIX filesystems (and NTFS, for a same-volume rename) is
atomic: a concurrent reader always sees either the fully-old or the
fully-new content, never a partial write. If any step fails, the original
file is untouched and the temporary file is cleaned up. `moveFile()` (used
by `card archive` / `card restore`) refuses to overwrite an existing
destination.

Lock files do not accumulate: `atomicWrite()`/`moveFile()` remove their lock
file before releasing it, using a stat-based check (device + inode) to
avoid the classic `flock()`-then-`unlink()` race where a lock file removed
and recreated between two processes could let both believe they hold
exclusivity — a process that finds its lock file's path no longer points at
the inode it locked simply retries against the current path instead of
proceeding.

`MarkdownCardRepository` confines every path it touches to the board root
and checks every path *component* between the root and the target for
symlinks (not just the final segment), for both reads and writes, so a card
path can't be used to escape the board directory via a symlink anywhere
along the way.

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
