<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Rendering\CardFieldSelection;

/**
 * How one CLI invocation should write its result: the format, plus the two
 * knobs that exist purely to keep board JSON small enough to hand to a
 * language model (`--compact`, `--fields=`).
 *
 * Both knobs only mean anything for `--format=json`, and both are rejected
 * rather than ignored with any other format — an agent that believes it asked
 * for reduced output and silently got the full document would be wrong about
 * its own budget.
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

        if ($format !== OutputFormat::Json) {
            if ($compact) {
                throw new ValidationException(
                    'Option --compact requires --format=json.',
                    field: 'compact',
                );
            }

            if ($fieldsValue !== null) {
                throw new ValidationException(
                    'Option --fields requires --format=json.',
                    field: 'fields',
                );
            }
        }

        return new self(
            $format,
            $compact,
            $fieldsValue === null ? null : CardFieldSelection::fromString($fieldsValue),
        );
    }

    /**
     * The options an error should be reported with when {@see self::fromArgs()}
     * itself threw — a bad `--fields` value, say.
     *
     * Never throws, and deliberately keeps `--format=json`: a caller that
     * cannot read anything but JSON must still get JSON back when the thing
     * that was wrong is the option it used to ask for smaller JSON.
     *
     * @param ParsedArgs $parsed
     */
    public static function errorFallbackFromArgs(array $parsed): self
    {
        $requestedFormat = $parsed['options']['format'] ?? null;
        $format = is_string($requestedFormat)
            ? OutputFormat::tryFrom($requestedFormat) ?? OutputFormat::Text
            : OutputFormat::Text;

        return new self(
            $format,
            $format === OutputFormat::Json && ($parsed['options']['compact'] ?? false) === true,
        );
    }

    public function isJson(): bool
    {
        return $this->format === OutputFormat::Json;
    }
}
