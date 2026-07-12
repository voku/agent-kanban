<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Support;

use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Claim;
use voku\AgentKanban\Domain\ExternalIssueRef;
use voku\AgentKanban\Domain\Lane;

/**
 * Builds minimal, valid {@see Card} instances for tests without repeating
 * the full constructor everywhere.
 */
final class CardFactory
{
    /**
     * @param array<string, string> $extensionFields
     */
    public static function make(
        string $id = 'ABC-1',
        string $lane = 'BACKLOG',
        string $status = '',
        ?string $domain = null,
        ?string $assignee = null,
        string $summary = '',
        string $nextAction = '',
        string $validation = '',
        ?int $priority = null,
        string $wave = '',
        string $taskBrief = '',
        string $handoffNotes = '',
        ?Claim $claim = null,
        ?ExternalIssueRef $externalIssue = null,
        int $formatVersion = 1,
        array $extensionFields = [],
        string $title = '',
        string $sourceFile = '',
    ): Card {
        $cardId = CardId::fromString($id);

        return new Card(
            id: $cardId,
            title: $title !== '' ? $title : $cardId->toString(),
            lane: Lane::fromString($lane),
            status: CardStatus::fromString($status),
            domain: $domain,
            assignee: $assignee,
            createdAt: null,
            createdAtRaw: '',
            updatedAt: null,
            updatedAtRaw: '',
            summary: $summary,
            nextAction: $nextAction,
            validation: $validation,
            priority: $priority,
            wave: $wave,
            taskBrief: $taskBrief,
            handoffNotes: $handoffNotes,
            claim: $claim,
            externalIssue: $externalIssue,
            formatVersion: $formatVersion,
            extensionFields: $extensionFields,
            extraSectionsRaw: '',
            revision: CardRevision::fromContent($cardId->toString()),
            sourceFile: $sourceFile !== '' ? $sourceFile : 'todo/cards/' . $cardId->toString() . '.md',
        );
    }
}
