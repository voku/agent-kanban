<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

final readonly class ExternalIssueDrift
{
    /**
     * @param list<ExternalIssueDriftEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<ExternalIssueDriftEntry>
     */
    public function ofKind(DriftKind $kind): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ExternalIssueDriftEntry $entry): bool => $entry->kind === $kind,
        ));
    }
}
