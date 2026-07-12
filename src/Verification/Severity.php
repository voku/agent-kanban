<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
