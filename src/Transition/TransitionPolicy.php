<?php

declare(strict_types=1);

namespace voku\AgentKanban\Transition;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ValidationException;

/**
 * Configurable lane-to-lane transition rules (`BoardConfig::$transitions`).
 * Validation is deliberately separate from writing a card file — see
 * {@see \voku\AgentKanban\Mutation\CardMutationService}, which calls
 * {@see self::validate()} before it ever opens a file for writing.
 *
 * Archiving a card is a distinct mutation operation, not a lane transition
 * (see `docs/concurrency.md`); this class only knows about moves between
 * the lanes in `BoardConfig::$lanes`.
 */
final readonly class TransitionPolicy
{
    public function __construct(
        private BoardConfig $config,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedTargets(Lane $from): array
    {
        return $this->config->transitions[$from->toString()] ?? [];
    }

    public function canTransition(Lane $from, Lane $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }

        return in_array($to->toString(), $this->allowedTargets($from), true);
    }

    public function validate(Lane $from, Lane $to): void
    {
        if ($this->canTransition($from, $to)) {
            return;
        }

        $allowed = $this->allowedTargets($from);

        throw new ValidationException(
            sprintf(
                'Cannot move from %s to %s. Allowed targets from %s: %s.',
                $from,
                $to,
                $from,
                $allowed === [] ? '(none configured)' : implode(', ', $allowed),
            ),
            field: 'lane',
        );
    }
}
