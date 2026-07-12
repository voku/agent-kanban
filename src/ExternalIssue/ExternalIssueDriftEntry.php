<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

final readonly class ExternalIssueDriftEntry
{
    public function __construct(
        public DriftKind $kind,
        public string $externalKey,
        public ?string $cardId = null,
        public ?string $localValue = null,
        public ?string $remoteValue = null,
    ) {
    }

    /**
     * @return array{kind: string, externalKey: string, cardId: string|null, localValue: string|null, remoteValue: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind'        => $this->kind->value,
            'externalKey' => $this->externalKey,
            'cardId'      => $this->cardId,
            'localValue'  => $this->localValue,
            'remoteValue' => $this->remoteValue,
        ];
    }
}
