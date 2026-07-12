<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

final readonly class Violation
{
    public function __construct(
        public ViolationCode $code,
        public string $message,
        public Severity $severity,
        public ?string $cardId = null,
        public ?string $field = null,
        public ?string $file = null,
    ) {
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     severity: string,
     *     cardId: string|null,
     *     field: string|null,
     *     file: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'code'     => $this->code->value,
            'message'  => $this->message,
            'severity' => $this->severity->value,
            'cardId'   => $this->cardId,
            'field'    => $this->field,
            'file'     => $this->file,
        ];
    }
}
