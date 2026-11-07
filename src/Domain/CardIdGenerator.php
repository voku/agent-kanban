<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use Closure;
use Random\Randomizer;
use voku\AgentKanban\Exception\ValidationException;

/**
 * Generates collision-resistant numeric card IDs without relying on a
 * branch-local sequence.
 *
 * With Git branch context, the high 20 bits are a truncated SHA-256 hash of
 * the branch name and the low 43 bits come from a CSPRNG. Without branch
 * context, the existing uniform positive 63-bit generator remains the
 * fallback. Both modes preserve the PREFIX-NUMBER format and existing IDs.
 *
 * The branch hash is not a unique branch identifier: two different branches
 * can share its 20-bit namespace. The random suffix still distinguishes their
 * cards. For n cards created on the same branch, the approximate collision
 * probability is n(n-1) / 2^44 (before local occupancy retries).
 */
final readonly class CardIdGenerator
{
    private const int MAX_ATTEMPTS = 3;

    private const int RANDOM_BITS = 43;

    private const int RANDOM_MAX = (1 << self::RANDOM_BITS) - 1;

    /**
     * @param int $intSize bytes per PHP integer; injectable only so the unsupported-build branch is testable
     */
    public function __construct(private Randomizer $randomizer = new Randomizer(), private int $intSize = PHP_INT_SIZE)
    {
    }

    /**
     * @param Closure(CardId): bool $exists whether the candidate is already in use, including archived cards
     * @param ?string $branchName exact Git branch name; null/empty means Git context is unavailable
     *
     * @throws ValidationException on a 32-bit PHP build, or when no unused candidate was found
     */
    public function generate(string $prefix, Closure $exists, ?string $branchName = null): CardId
    {
        if ($this->intSize < 8) {
            // A 32-bit PHP build cannot read the existing positive 63-bit card IDs either.
            throw new ValidationException('Generated card IDs need a 64-bit PHP build (63-bit numbers); pass an explicit ID instead.', field: 'id');
        }

        $branchHash = $branchName !== null && $branchName !== ''
            ? (int) hexdec(substr(hash('sha256', $branchName), 0, 5))
            : null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $number = $branchHash === null
                ? $this->randomizer->getInt(1, PHP_INT_MAX)
                : ($branchHash << self::RANDOM_BITS) | $this->randomizer->getInt(1, self::RANDOM_MAX);

            $candidate = CardId::of($prefix, $number);
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
