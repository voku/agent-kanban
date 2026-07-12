<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use voku\AgentKanban\Exception\ValidationException;

/**
 * A card identifier such as `ITPNG-123`: an uppercase alphanumeric project
 * prefix, a dash, and a positive integer number.
 *
 * Format validation only; whether a given prefix belongs to a specific board
 * is a {@see \voku\AgentKanban\Config\BoardConfig} / verifier concern, not
 * something CardId itself can know.
 */
final readonly class CardId
{
    private const string PATTERN = '/^([A-Z][A-Z0-9]*)-([1-9][0-9]*)$/';

    private function __construct(
        private string $value,
        public string $prefix,
        public int $number,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (str_contains($value, "\0")) {
            throw new ValidationException('Card ID must not contain NUL bytes.', field: 'id');
        }

        $normalized = strtoupper(trim($value));
        if (preg_match(self::PATTERN, $normalized, $matches) !== 1) {
            throw new ValidationException(
                sprintf('Invalid card ID "%s": expected format PREFIX-NUMBER (e.g. ABC-123).', $value),
                field: 'id',
            );
        }

        return new self($normalized, $matches[1], (int) $matches[2]);
    }

    public static function of(string $prefix, int $number): self
    {
        if ($number <= 0) {
            throw new ValidationException('Card number must be a positive integer.', field: 'id');
        }

        return self::fromString($prefix . '-' . $number);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
