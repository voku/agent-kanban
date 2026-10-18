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

    public function __construct(private Randomizer $randomizer = new Randomizer())
    {
    }

    /**
     * @param Closure(CardId): bool $exists whether the candidate is already in use on the local board
     *
     * @throws ValidationException when no unused candidate was found; an existing ID is never reused
     */
    public function generate(string $prefix, Closure $exists): CardId
    {
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
