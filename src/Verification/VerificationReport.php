<?php

declare(strict_types=1);

namespace voku\AgentKanban\Verification;

final readonly class VerificationReport
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public array $violations,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<Violation>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (Violation $violation): bool => $violation->severity === Severity::Error,
        ));
    }

    /**
     * @return list<Violation>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (Violation $violation): bool => $violation->severity === Severity::Warning,
        ));
    }
}
