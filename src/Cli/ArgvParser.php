<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

/**
 * A small, dependency-free `--option=value` / `--flag` / positional-argument
 * splitter shared by every CLI command.
 *
 * @phpstan-type ParsedArgs array{positional: list<string>, options: array<string, string|bool>}
 */
final class ArgvParser
{
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
            if ($separatorPosition === false) {
                $options[$withoutPrefix] = true;

                continue;
            }

            $options[substr($withoutPrefix, 0, $separatorPosition)] = substr($withoutPrefix, $separatorPosition + 1);
        }

        return ['positional' => $positional, 'options' => $options];
    }

    /**
     * @param ParsedArgs $parsed
     */
    public static function stringOption(array $parsed, string $name, ?string $default = null): ?string
    {
        $value = $parsed['options'][$name] ?? null;
        if ($value === null) {
            return $default;
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * @param ParsedArgs $parsed
     */
    public static function boolOption(array $parsed, string $name): bool
    {
        return isset($parsed['options'][$name]) && $parsed['options'][$name] !== false;
    }

    /**
     * @param ParsedArgs $parsed
     */
    public static function intOption(array $parsed, string $name, int $default = 0): int
    {
        $value = self::stringOption($parsed, $name);
        if ($value === null || preg_match('/^-?\d+$/', $value) !== 1) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param ParsedArgs $parsed
     */
    public static function intOptionOrNull(array $parsed, string $name): ?int
    {
        $value = self::stringOption($parsed, $name);
        if ($value === null || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
