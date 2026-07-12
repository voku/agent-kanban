<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use voku\AgentKanban\Exception\ValidationException;

/**
 * A board lane name, e.g. `READY` or `DOING`.
 *
 * Lanes are host-configurable (see {@see \voku\AgentKanban\Config\BoardConfig}),
 * so this is a validated value object rather than a native PHP enum. It only
 * validates *shape* (uppercase identifier); whether a lane is actually
 * supported on a given board is checked against `BoardConfig::$lanes`.
 */
final readonly class Lane
{
    private const string PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (str_contains($value, "\0")) {
            throw new ValidationException('Lane must not contain NUL bytes.', field: 'lane');
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '' || preg_match(self::PATTERN, $normalized) !== 1) {
            throw new ValidationException(
                sprintf('Invalid lane "%s": expected an uppercase identifier (e.g. READY).', $value),
                field: 'lane',
            );
        }

        return new self($normalized);
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
