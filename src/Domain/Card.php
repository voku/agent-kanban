<?php

declare(strict_types=1);

namespace voku\AgentKanban\Domain;

use DateTimeImmutable;

/**
 * An immutable, fully-parsed representation of one card file.
 *
 * A Card is a plain data object: it never touches the filesystem and never
 * validates against board policy (lane support, WIP limits, required fields
 * per lane, ...). That is the job of
 * {@see \voku\AgentKanban\Verification\BoardVerifier}. CardParser only
 * enforces that the file is *structurally* readable (see
 * {@see \voku\AgentKanban\Repository\CardParser}).
 *
 * @phpstan-type ExtensionFields array<string, string>
 */
final readonly class Card
{
    /**
     * @param ExtensionFields $extensionFields Unknown bullet-metadata fields, keyed by
     *                                         their original label, preserved verbatim
     *                                         for round-trip serialization.
     */
    public function __construct(
        public CardId $id,
        public string $title,
        public Lane $lane,
        public CardStatus $status,
        public ?string $domain,
        public ?string $assignee,
        public ?DateTimeImmutable $createdAt,
        public string $createdAtRaw,
        public ?DateTimeImmutable $updatedAt,
        public string $updatedAtRaw,
        public string $summary,
        public string $nextAction,
        public string $validation,
        public ?int $priority,
        public string $wave,
        public string $taskBrief,
        public string $handoffNotes,
        public ?Claim $claim,
        public ?ExternalIssueRef $externalIssue,
        public int $formatVersion,
        public array $extensionFields,
        public string $extraSectionsRaw,
        public CardRevision $revision,
        public string $sourceFile,
    ) {
    }

    public function withLane(Lane $lane): self
    {
        return $this->with(lane: $lane);
    }

    public function withStatus(CardStatus $status): self
    {
        return $this->with(status: $status);
    }

    public function withClaim(?Claim $claim): self
    {
        return $this->with(claim: $claim);
    }

    /**
     * @param ExtensionFields|null $extensionFields
     */
    public function with(
        ?Lane $lane = null,
        ?CardStatus $status = null,
        ?string $title = null,
        ?string $domain = null,
        ?string $assignee = null,
        ?DateTimeImmutable $updatedAt = null,
        ?string $summary = null,
        ?string $nextAction = null,
        ?string $validation = null,
        ?int $priority = null,
        ?string $wave = null,
        ?string $taskBrief = null,
        ?string $handoffNotes = null,
        ?Claim $claim = null,
        bool $clearClaim = false,
        ?ExternalIssueRef $externalIssue = null,
        ?array $extensionFields = null,
        ?CardRevision $revision = null,
    ): self {
        return new self(
            $this->id,
            $title ?? $this->title,
            $lane ?? $this->lane,
            $status ?? $this->status,
            $domain ?? $this->domain,
            $assignee ?? $this->assignee,
            $this->createdAt,
            $this->createdAtRaw,
            $updatedAt ?? $this->updatedAt,
            $updatedAt !== null ? $updatedAt->format(DATE_ATOM) : $this->updatedAtRaw,
            $summary ?? $this->summary,
            $nextAction ?? $this->nextAction,
            $validation ?? $this->validation,
            $priority ?? $this->priority,
            $wave ?? $this->wave,
            $taskBrief ?? $this->taskBrief,
            $handoffNotes ?? $this->handoffNotes,
            $clearClaim ? null : ($claim ?? $this->claim),
            $externalIssue ?? $this->externalIssue,
            $this->formatVersion,
            $extensionFields ?? $this->extensionFields,
            $this->extraSectionsRaw,
            $revision ?? $this->revision,
            $this->sourceFile,
        );
    }
}
