<?php

declare(strict_types=1);

namespace voku\AgentKanban;

/**
 * @deprecated since 0.2.0, kept as a compatibility value object for the
 *             deprecated {@see TodoBoardCli}. Use
 *             {@see \voku\AgentKanban\Domain\Card} for the full, typed card
 *             model instead. See UPGRADING.md.
 */
final readonly class TodoBoardCard
{
    public function __construct(
        public string $lane,
        public string $ticket,
        public string $status,
        public string $domain,
        public string $assignee,
        public string $updated,
        public string $fit,
        public string $summary,
        public string $nextAction,
    ) {
    }
}
