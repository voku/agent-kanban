<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

enum DriftKind: string
{
    case MissingLocally = 'missing-locally';
    case NoLongerActiveRemotely = 'no-longer-active-remotely';
    case StatusDrift = 'status-drift';
    case LaneDrift = 'lane-drift';
    case SummaryDrift = 'summary-drift';
    case UpdateTimeDrift = 'update-time-drift';
}
