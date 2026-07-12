<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

/**
 * Stable, machine-readable violation codes. These are part of the public
 * JSON contract (`docs/json-format.md`) — never renumber or reuse a value,
 * only add new ones.
 */
enum ViolationCode: string
{
    case DuplicateCardId = 'duplicate-card-id';
    case InvalidFilename = 'invalid-filename';
    case InvalidProjectPrefix = 'invalid-project-prefix';
    case UnsupportedLane = 'unsupported-lane';
    case InvalidStatusLaneMapping = 'invalid-status-lane-mapping';
    case MissingRequiredField = 'missing-required-field';
    case MissingTaskBrief = 'missing-task-brief';
    case InvalidTimestamp = 'invalid-timestamp';
    case MalformedMetadata = 'malformed-metadata';
    case DuplicateMetadataField = 'duplicate-metadata-field';
    case InvalidWipCount = 'invalid-wip-count';
    case InvalidClaim = 'invalid-claim';
    case InvalidTransitionState = 'invalid-transition-state';
    case BoardMetadataInconsistency = 'board-metadata-inconsistency';
    case StaleFormatVersion = 'stale-format-version';
    case IncompatibleFormatVersion = 'incompatible-format-version';
    case ArchiveConflict = 'archive-conflict';
    case SourceDirectoryAmbiguity = 'source-directory-ambiguity';
}
