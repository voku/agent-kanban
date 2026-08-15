<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Rendering\CardFieldSelection;

/**
 * How one CLI invocation should write its result.
 *
 * `--fields=` is a semantic projection and therefore applies to either
 * structured card format (`json` or `toon`). `--compact` only controls JSON
 * whitespace and is rejected for every other format rather than silently doing
 * nothing.
 *
 * @phpstan-import-type ParsedArgs from ArgvParser
 */
final readonly class OutputOptions
{
    public function __construct(
        public OutputFormat $format = OutputFormat::Text,
        public bool $compact = false,
        public ?CardFieldSelection $fields = null,
    ) {
    }

    /**
     * @param ParsedArgs $parsed
     */
    public static function fromArgs(array $parsed): self
    {
        $format = OutputFormat::fromString(ArgvParser::stringOption($parsed, 'format'));
        $compact = ArgvParser::boolOption($parsed, 'compact');
        $fieldsValue = ArgvParser::stringOption($parsed, 'fields');

        if ($format !== OutputFormat::Json && $compact) {
            throw new ValidationException(
                'Option --compact requires --format=json.',
                field: 'compact',
            );
        }

        if (!in_array($format, [OutputFormat::Json, OutputFormat::Toon], true) && $fieldsValue !== null) {
            throw new ValidationException(
                'Option --fields requires --format=json or --format=toon.',
                field: 'fields',
            );
        }

        return new self(
            $format,
            $compact,
            $fieldsValue === null ? null : CardFieldSelection::fromString($fieldsValue),
        );
    }

    /**
     * The options an error should be reported with when parsing failed before a
     * real {@see self} could be built.
     *
     * Reads the raw tokens because the earliest failures happen before a parsed
     * argument array exists. It never throws. A valid requested structured
     * format is preserved so a JSON-only or TOON-only consumer still receives a
     * parseable error document.
     *
     * @param list<string> $tokens
     */
    public static function errorFallbackFromTokens(array $tokens): self
    {
        $format = OutputFormat::Text;
        $compact = false;
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (str_starts_with($token, '--format=')) {
                $format = OutputFormat::tryFrom(substr($token, strlen('--format='))) ?? OutputFormat::Text;
                continue;
            }
            if ($token === '--format') {
                $candidate = $tokens[$index + 1] ?? null;
                if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                    $format = OutputFormat::tryFrom($candidate) ?? OutputFormat::Text;
                    ++$index;
                }
                continue;
            }
            if ($token === '--compact') {
                $compact = true;
            }
        }

        return new self($format, $format === OutputFormat::Json && $compact);
    }

    public function isJson(): bool
    {
        return $this->format === OutputFormat::Json;
    }

    public function isStructured(): bool
    {
        return in_array($this->format, [OutputFormat::Json, OutputFormat::Toon], true);
    }
}
