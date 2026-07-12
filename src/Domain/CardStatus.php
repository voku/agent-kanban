<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use voku\AgentKanban\Exception\ValidationException;

/**
 * A free-form status label such as `Selected` or `In Progress`.
 *
 * Statuses are host-defined text (they may come from an external tracker
 * such as Jira), so no fixed set of values is enforced here. Whether a status
 * is valid for a lane is an optional, host-configured
 * {@see \voku\AgentKanban\Config\BoardConfig::$statusToLane} check performed by
 * the verifier, not a constraint of this value object.
 */
final readonly class CardStatus
{
    private const int MAX_LENGTH = 256;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (str_contains($value, "\0")) {
            throw new ValidationException('Status must not contain NUL bytes.', field: 'status');
        }

        $trimmed = trim($value);
        if (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', $trimmed) === 1) {
            throw new ValidationException('Status must not contain control characters.', field: 'status');
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw new ValidationException(
                sprintf('Status must be at most %d characters.', self::MAX_LENGTH),
                field: 'status',
            );
        }

        return new self($trimmed);
    }

    public static function none(): self
    {
        return new self('');
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function equalsIgnoreCase(self $other): bool
    {
        return strcasecmp($this->value, $other->value) === 0;
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
