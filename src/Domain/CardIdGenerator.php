<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use Closure;
use Random\Randomizer;
use voku\AgentKanban\Exception\ValidationException;

/**
 * Generates collision-resistant card IDs for boards that have no external
 * ticket authority and are edited on independent Git branches.
 *
 * The number is a uniformly random positive 63-bit integer from a CSPRNG, never
 * derived from the highest number on the current board, so two branches created
 * from the same base cannot be steered to the same value by their identical
 * starting state. The ID keeps the `PREFIX-NUMBER` shape, so every existing ID,
 * card file and external ticket ID stays valid and unchanged.
 *
 * Probability that two of n generated IDs collide is about n^2 / 2^64; the
 * existence check below only guards the local board, it does not coordinate
 * across clones.
 */
final readonly class CardIdGenerator
{
    private const int MAX_ATTEMPTS = 3;

    /**
     * @param int $intSize bytes per PHP integer; injectable only so the unsupported-build branch is testable
     */
    public function __construct(private Randomizer $randomizer = new Randomizer(), private int $intSize = PHP_INT_SIZE)
    {
    }

    /**
     * @param Closure(CardId): bool $exists whether the candidate is already in use on the local board, including archived cards
     *
     * @throws ValidationException on a 32-bit PHP build, or when no unused candidate was found; an existing ID is never reused
     */
    public function generate(string $prefix, Closure $exists): CardId
    {
        if ($this->intSize < 8) {
            // On a 32-bit build PHP_INT_MAX is 2^31-1, so the documented 63-bit space (and its collision bound) would silently shrink.
            throw new ValidationException('Generated card IDs need a 64-bit PHP build (63-bit numbers); pass an explicit ID instead.', field: 'id');
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $candidate = CardId::of($prefix, $this->randomizer->getInt(1, PHP_INT_MAX));
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        throw new ValidationException(
            sprintf('Could not generate an unused card ID for prefix "%s" after %d attempts.', $prefix, self::MAX_ATTEMPTS),
            field: 'id',
        );
    }
}
