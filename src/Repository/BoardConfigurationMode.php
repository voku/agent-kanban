<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

enum BoardConfigurationMode: string
{
    case JSON = 'json';
    case METADATA = 'metadata';
    case INFERRED = 'inferred';
}
