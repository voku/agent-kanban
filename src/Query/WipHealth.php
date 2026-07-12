<?php

declare(strict_types=1);

namespace voku\AgentKanban\Query;

final readonly class WipHealth
{
    /**
     * @param list<WipGroupStatus> $groups
     */
    public function __construct(
        public array $groups,
    ) {
    }

    public function isHealthy(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->isOverLimit()) {
                return false;
            }
        }

        return true;
    }
}
