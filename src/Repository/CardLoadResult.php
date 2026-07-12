<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Domain\CardCollection;

/**
 * The result of a lenient board load: every card that parsed successfully,
 * plus every file that did not.
 */
final readonly class CardLoadResult
{
    /**
     * @param list<CardLoadFailure> $failures
     */
    public function __construct(
        public CardCollection $cards,
        public array $failures,
    ) {
    }
}
