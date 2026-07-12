<?php

declare(strict_types=1);

namespace voku\AgentKanban\ExternalIssue;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardCollection;

/**
 * Produces a structured diff between local cards and the records an
 * {@see ExternalIssueProvider} returned. Never talks to a network itself and
 * never assumes a particular tracker's status vocabulary.
 *
 * A card participates in comparison if it either carries an explicit
 * `- **External issue:** <system>:<key>` reference, or its card ID matches a
 * remote record's key directly (the common convention where the local
 * ticket ID *is* the external key).
 */
final class ExternalIssueComparator
{
    /**
     * @param list<ExternalIssueRecord> $remoteIssues
     */
    public function compare(CardCollection $cards, array $remoteIssues, BoardConfig $config): ExternalIssueDrift
    {
        $localByKey = $this->indexCardsByExternalKey($cards);
        $remoteByKey = [];
        foreach ($remoteIssues as $issue) {
            $remoteByKey[$issue->key] = $issue;
        }

        $statusToLane = $this->reverseStatusToLane($config);
        $entries = [];

        foreach ($remoteByKey as $key => $issue) {
            if (!isset($localByKey[$key])) {
                $entries[] = new ExternalIssueDriftEntry(DriftKind::MissingLocally, $key, remoteValue: $issue->summary);

                continue;
            }

            $card = $localByKey[$key];

            if ($card->status->toString() !== $issue->status) {
                $entries[] = new ExternalIssueDriftEntry(
                    DriftKind::StatusDrift,
                    $key,
                    $card->id->toString(),
                    $card->status->toString(),
                    $issue->status,
                );
            }

            if ($card->summary !== '' && $issue->summary !== '' && $card->summary !== $issue->summary) {
                $entries[] = new ExternalIssueDriftEntry(
                    DriftKind::SummaryDrift,
                    $key,
                    $card->id->toString(),
                    $card->summary,
                    $issue->summary,
                );
            }

            if ($card->updatedAt !== null && $issue->updatedAt !== null && $card->updatedAt != $issue->updatedAt) {
                $entries[] = new ExternalIssueDriftEntry(
                    DriftKind::UpdateTimeDrift,
                    $key,
                    $card->id->toString(),
                    $card->updatedAt->format('Y-m-d\TH:i:sP'),
                    $issue->updatedAt->format('Y-m-d\TH:i:sP'),
                );
            }

            $suggestedLane = $statusToLane[strtolower($issue->status)] ?? null;
            if ($suggestedLane !== null && $suggestedLane !== $card->lane->toString()) {
                $entries[] = new ExternalIssueDriftEntry(
                    DriftKind::LaneDrift,
                    $key,
                    $card->id->toString(),
                    $card->lane->toString(),
                    $suggestedLane,
                );
            }
        }

        foreach ($localByKey as $key => $card) {
            if (!isset($remoteByKey[$key])) {
                $entries[] = new ExternalIssueDriftEntry(DriftKind::NoLongerActiveRemotely, $key, $card->id->toString());
            }
        }

        return new ExternalIssueDrift($entries);
    }

    /**
     * @return array<string, Card>
     */
    private function indexCardsByExternalKey(CardCollection $cards): array
    {
        $byKey = [];
        foreach ($cards->all() as $card) {
            $key = $card->externalIssue->key ?? $card->id->toString();
            $byKey[$key] = $card;
        }

        return $byKey;
    }

    /**
     * Builds a `strtolower(status) => lane` map, keeping only statuses that
     * unambiguously belong to a single lane.
     *
     * @return array<string, string>
     */
    private function reverseStatusToLane(BoardConfig $config): array
    {
        $counts = [];
        $reverse = [];

        foreach ($config->statusToLane as $lane => $statuses) {
            foreach ($statuses as $status) {
                $key = strtolower($status);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $reverse[$key] = $lane;
            }
        }

        foreach ($counts as $status => $count) {
            if ($count > 1) {
                unset($reverse[$status]);
            }
        }

        return $reverse;
    }
}
