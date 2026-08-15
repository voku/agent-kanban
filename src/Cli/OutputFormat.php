<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use voku\AgentKanban\Exception\ValidationException;

enum OutputFormat: string
{
    case Text = 'text';
    case Markdown = 'markdown';
    case Json = 'json';

    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::Text;
        }

        return self::tryFrom($value) ?? throw new ValidationException(
            sprintf('Invalid --format "%s": expected text, markdown, or json.', $value),
            field: 'format',
        );
    }
}
