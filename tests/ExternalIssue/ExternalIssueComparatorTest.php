<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\ExternalIssue;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Domain\ExternalIssueRef;
use voku\AgentKanban\ExternalIssue\DriftKind;
use voku\AgentKanban\ExternalIssue\ExternalIssueComparator;
use voku\AgentKanban\ExternalIssue\ExternalIssueRecord;
use voku\AgentKanban\Tests\Support\CardFactory;

final class ExternalIssueComparatorTest extends TestCase
{
    public function testMissingLocallyWhenRemoteHasNoMatchingCard(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1')]);
        $remote = [new ExternalIssueRecord('ABC-2', 'Summary', 'Selected', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        self::assertCount(1, $drift->ofKind(DriftKind::MissingLocally));
    }

    public function testNoLongerActiveRemotelyWhenLocalCardHasNoRemoteMatch(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1')]);

        $drift = (new ExternalIssueComparator())->compare($cards, [], BoardConfig::default('ABC'));

        self::assertCount(1, $drift->ofKind(DriftKind::NoLongerActiveRemotely));
    }

    public function testMatchesByCardIdWhenNoExplicitExternalIssueRef(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', status: 'Selected')]);
        $remote = [new ExternalIssueRecord('ABC-1', 'Summary', 'Selected', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        self::assertTrue($drift->isEmpty());
    }

    public function testMatchesByExplicitExternalIssueRef(): void
    {
        $ref = new ExternalIssueRef('jira', 'PROJ-9');
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', status: 'Selected', externalIssue: $ref)]);
        $remote = [new ExternalIssueRecord('PROJ-9', 'Summary', 'Selected', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        self::assertTrue($drift->isEmpty());
    }

    public function testStatusDrift(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', status: 'Selected')]);
        $remote = [new ExternalIssueRecord('ABC-1', '', 'In Progress', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        $entries = $drift->ofKind(DriftKind::StatusDrift);
        self::assertCount(1, $entries);
        self::assertSame('Selected', $entries[0]->localValue);
        self::assertSame('In Progress', $entries[0]->remoteValue);
    }

    public function testSummaryDrift(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', status: 'Selected', summary: 'Local summary')]);
        $remote = [new ExternalIssueRecord('ABC-1', 'Remote summary', 'Selected', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        self::assertCount(1, $drift->ofKind(DriftKind::SummaryDrift));
    }

    public function testUpdateTimeDrift(): void
    {
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', status: 'Selected')]);
        $remote = [new ExternalIssueRecord('ABC-1', '', 'Selected', new \DateTimeImmutable('2026-01-01'))];

        // Local card has no updatedAt (null in the factory), so update-time
        // drift only fires when both sides have a timestamp; this asserts it
        // is correctly skipped rather than false-positiving on a null.
        $drift = (new ExternalIssueComparator())->compare($cards, $remote, BoardConfig::default('ABC'));

        self::assertCount(0, $drift->ofKind(DriftKind::UpdateTimeDrift));
    }

    public function testLaneDriftSuggestsUnambiguousMapping(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: [], statusToLane: [
            'READY' => ['Selected'],
            'DOING' => ['In Progress'],
        ]);
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', lane: 'READY', status: 'Selected')]);
        $remote = [new ExternalIssueRecord('ABC-1', '', 'In Progress', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, $config);

        $entries = $drift->ofKind(DriftKind::LaneDrift);
        self::assertCount(1, $entries);
        self::assertSame('DOING', $entries[0]->remoteValue);
    }

    public function testAmbiguousStatusToLaneMappingProducesNoLaneDriftSuggestion(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: [], statusToLane: [
            'READY' => ['Selected'],
            'DOING' => ['Selected'],
        ]);
        $cards = CardCollection::fromArray([CardFactory::make('ABC-1', lane: 'READY', status: 'Backlog')]);
        $remote = [new ExternalIssueRecord('ABC-1', '', 'Selected', null)];

        $drift = (new ExternalIssueComparator())->compare($cards, $remote, $config);

        self::assertCount(0, $drift->ofKind(DriftKind::LaneDrift));
    }
}
