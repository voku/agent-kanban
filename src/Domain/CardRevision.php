<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use voku\AgentKanban\Exception\ValidationException;

/**
 * An optimistic-concurrency token derived from stable card content.
 *
 * The revision is a SHA-256 hex digest of the exact bytes a mutation read
 * from disk. It changes if and only if those bytes change, which makes it
 * safe to use as an `--expected-revision` conflict check without a database.
 */
final readonly class CardRevision
{
    private const int HEX_LENGTH = 64;

    private function __construct(public string $hash)
    {
    }

    public static function fromContent(string $content): self
    {
        return new self(hash('sha256', $content));
    }

    public static function fromHex(string $hex): self
    {
        $normalized = strtolower(trim($hex));
        if (
            strlen($normalized) !== self::HEX_LENGTH
            || preg_match('/^[0-9a-f]+$/', $normalized) !== 1
        ) {
            throw new ValidationException(
                sprintf('Invalid revision hash: expected %d hex characters.', self::HEX_LENGTH),
            );
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function __toString(): string
    {
        return $this->hash;
    }
}
