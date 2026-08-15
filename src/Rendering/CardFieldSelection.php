<?php

declare(strict_types=1);

namespace voku\AgentKanban\Rendering;

use voku\AgentKanban\Exception\ValidationException;

/**
 * An explicit projection over the JSON `card` object.
 *
 * A full card object carries every field a card file can hold, including the
 * unbounded `taskBrief` and `handoffNotes` prose and the 64-character
 * `revision` digest. An orchestrator that only needs to rank or dispatch work
 * (`voku/agent-loop`) pays for all of that on every card of every listing,
 * which is the dominant cost when board JSON is fed to a language model.
 *
 * A selection names the fields that are actually needed. It never invents,
 * renames or reorders anything: the projected object is a subset of the same
 * `card` shape, emitted in the canonical field order documented in
 * `docs/json-format.md`, so a consumer can keep one parser for both.
 *
 * `id` is always part of a selection, whether or not it was named — a card
 * object that cannot be tied back to a card is not a cheaper answer, it is an
 * unusable one.
 *
 * @phpstan-import-type CardArray from JsonBoardRenderer
 */
final readonly class CardFieldSelection
{
    /**
     * Every field a `card` object can carry, in the canonical order used by
     * {@see JsonBoardRenderer::cardToArray()} and `docs/json-format.md`.
     *
     * @var list<string>
     */
    public const array AVAILABLE_FIELDS = [
        'id',
        'title',
        'lane',
        'status',
        'domain',
        'assignee',
        'createdAt',
        'updatedAt',
        'summary',
        'nextAction',
        'validation',
        'priority',
        'wave',
        'taskBrief',
        'handoffNotes',
        'claim',
        'externalIssue',
        'formatVersion',
        'extensionFields',
        'revision',
        'sourceFile',
    ];

    /**
     * The one field every selection carries, so a projected card is always
     * identifiable.
     */
    public const string ALWAYS_INCLUDED_FIELD = 'id';

    /**
     * @param list<string> $fields Canonically ordered, unique, always containing {@see self::ALWAYS_INCLUDED_FIELD}.
     */
    private function __construct(public array $fields)
    {
    }

    /**
     * Parses a comma-separated field list (the CLI's `--fields=` value).
     *
     * Strict in the same way the rest of the option parsing is: an unknown
     * field name, an empty entry, or a repeated entry is rejected rather than
     * silently dropped, because a silently ignored field name would hand back
     * a card object missing data the caller believes it asked for.
     */
    public static function fromString(string $value): self
    {
        $requested = [];

        foreach (explode(',', $value) as $part) {
            $name = trim($part);

            if ($name === '') {
                throw new ValidationException(
                    'Option --fields must not contain an empty field name.',
                    field: 'fields',
                );
            }

            if (!in_array($name, self::AVAILABLE_FIELDS, true)) {
                throw new ValidationException(
                    sprintf(
                        'Unknown card field "%s" in --fields. Available fields: %s.',
                        $name,
                        implode(', ', self::AVAILABLE_FIELDS),
                    ),
                    field: 'fields',
                );
            }

            if (in_array($name, $requested, true)) {
                throw new ValidationException(
                    sprintf('Card field "%s" may only appear once in --fields.', $name),
                    field: 'fields',
                );
            }

            $requested[] = $name;
        }

        return new self(array_values(array_filter(
            self::AVAILABLE_FIELDS,
            static fn (string $field): bool => $field === self::ALWAYS_INCLUDED_FIELD
                || in_array($field, $requested, true),
        )));
    }

    /**
     * Every field — the projection is then a no-op, useful as an explicit
     * "no reduction requested" value.
     */
    public static function all(): self
    {
        return new self(self::AVAILABLE_FIELDS);
    }

    public function includes(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    /**
     * @param CardArray $card
     *
     * @return array<string, mixed>
     */
    public function apply(array $card): array
    {
        $projected = [];

        foreach ($this->fields as $field) {
            if (array_key_exists($field, $card)) {
                $projected[$field] = $card[$field];
            }
        }

        return $projected;
    }
}
