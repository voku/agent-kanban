<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Exception\ValidationException;

/**
 * @phpstan-type ParsedArgs array{positional: list<string>, options: array<string, string|bool>}
 */
final class ArgvParser
{
    /** @var list<string> */
    private const array KNOWN_OPTIONS = [
        'format', 'root', 'config', 'lanes', 'lane', 'domain', 'assignee',
        'status', 'search', 'limit', 'dry-run', 'expected-revision', 'title',
        'summary', 'priority', 'wave', 'brief', 'handoff', 'next', 'validation',
        'to', 'actor', 'by', 'expires', 'move-to-doing', 'provider-class', 'query',
    ];

    /** @var list<string> */
    private const array BOOLEAN_OPTIONS = ['dry-run', 'move-to-doing'];

    /**
     * @param list<string> $tokens
     *
     * @return ParsedArgs
     */
    public static function parse(array $tokens): array
    {
        $positional = [];
        $options = [];

        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $positional[] = $token;
                continue;
            }

            $withoutPrefix = substr($token, 2);
            $separatorPosition = strpos($withoutPrefix, '=');
            $name = $separatorPosition === false ? $withoutPrefix : substr($withoutPrefix, 0, $separatorPosition);

            if ($name === '' || !in_array($name, self::KNOWN_OPTIONS, true)) {
                throw new ValidationException(sprintf('Unknown option: --%s.', $name));
            }
            if (array_key_exists($name, $options)) {
                throw new ValidationException(sprintf('Option --%s may only be supplied once.', $name));
            }

            if ($separatorPosition === false) {
                if (!in_array($name, self::BOOLEAN_OPTIONS, true)) {
                    throw new ValidationException(sprintf('Option --%s requires a value using --%s=<value>.', $name, $name));
                }
                $options[$name] = true;
                continue;
            }

            if (in_array($name, self::BOOLEAN_OPTIONS, true)) {
                throw new ValidationException(sprintf('Boolean option --%s must not have a value.', $name));
            }

            $value = substr($withoutPrefix, $separatorPosition + 1);
            if ($value === '') {
                throw new ValidationException(sprintf('Option --%s requires a non-empty value.', $name));
            }
            $options[$name] = $value;
        }

        return ['positional' => $positional, 'options' => $options];
    }

    /** @param ParsedArgs $parsed */
    public static function stringOption(array $parsed, string $name, ?string $default = null): ?string
    {
        $value = $parsed['options'][$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new ValidationException(sprintf('Option --%s requires a value.', $name));
        }

        return $value;
    }

    /** @param ParsedArgs $parsed */
    public static function boolOption(array $parsed, string $name): bool
    {
        return ($parsed['options'][$name] ?? false) === true;
    }

    /** @param ParsedArgs $parsed */
    public static function intOption(array $parsed, string $name, int $default = 0): int
    {
        $value = self::stringOption($parsed, $name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new ValidationException(sprintf('Option --%s requires an integer value.', $name));
        }

        return (int) $value;
    }

    /** @param ParsedArgs $parsed */
    public static function intOptionOrNull(array $parsed, string $name): ?int
    {
        $value = self::stringOption($parsed, $name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new ValidationException(sprintf('Option --%s requires an integer value.', $name));
        }

        return (int) $value;
    }
}
