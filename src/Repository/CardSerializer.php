<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use voku\AgentKanban\Domain\Card;

/**
 * Serializes a {@see Card} back into the canonical Markdown card format.
 *
 * Deterministic: the same Card always serializes to the same bytes (stable
 * field order, LF newlines, single trailing newline). See
 * `docs/card-format.md` for the full specification this implements.
 */
final class CardSerializer
{
    public function serialize(Card $card): string
    {
        $lines = [];
        $lines[] = '# ' . $card->id->toString() . ': ' . $card->title;
        $lines[] = '';

        foreach ($this->metadataLines($card) as $line) {
            $lines[] = $line;
        }

        $sections = [];

        if ($card->handoffNotes !== '') {
            $sections[] = "## Handoff / Context\n" . $card->handoffNotes;
        }

        if ($card->taskBrief !== '') {
            $sections[] = "## Agent Task Brief\n" . $card->taskBrief;
        }

        if ($card->extraSectionsRaw !== '') {
            $sections[] = $card->extraSectionsRaw;
        }

        $document = implode("\n", $lines);
        if ($sections !== []) {
            $document .= "\n\n" . implode("\n\n", $sections);
        }

        return rtrim($document, "\n") . "\n";
    }

    /**
     * @return list<string>
     */
    private function metadataLines(Card $card): array
    {
        $lines = [];
        $lines[] = $this->bullet('Ticket', $card->id->toString());
        $lines[] = $this->bullet('Lane', $card->lane->toString());

        if (!$card->status->isEmpty()) {
            $lines[] = $this->bullet('Status', $card->status->toString());
        }

        if ($card->domain !== null) {
            $lines[] = $this->bullet('Domain', $card->domain);
        }

        if ($card->assignee !== null) {
            $lines[] = $this->bullet('Assignee', $card->assignee);
        }

        if ($card->createdAt !== null) {
            $lines[] = $this->bullet('Created', $card->createdAt->format('Y-m-d\TH:i:sP'));
        } elseif ($card->createdAtRaw !== '') {
            $lines[] = $this->bullet('Created', $card->createdAtRaw);
        }

        if ($card->updatedAt !== null) {
            $lines[] = $this->bullet('Updated', $card->updatedAt->format('Y-m-d\TH:i:sP'));
        } elseif ($card->updatedAtRaw !== '') {
            $lines[] = $this->bullet('Updated', $card->updatedAtRaw);
        }

        if ($card->summary !== '') {
            $lines[] = $this->bullet('Summary', $card->summary);
        }

        if ($card->nextAction !== '') {
            $lines[] = $this->bullet('Next', $card->nextAction);
        }

        if ($card->validation !== '') {
            $lines[] = $this->bullet('Validation', $card->validation);
        }

        if ($card->priority !== null) {
            $lines[] = $this->bullet('Priority', (string) $card->priority);
        }

        if ($card->wave !== '') {
            $lines[] = $this->bullet('Wave', $card->wave);
        }

        if ($card->claim !== null) {
            $expires = $card->claim->expiresAt?->format('Y-m-d\TH:i:sP') ?? '-';
            $lines[] = $this->bullet('Claim', sprintf(
                '%s|claimed=%s|expires=%s|rev=%s',
                $card->claim->actor,
                $card->claim->claimedAt->format('Y-m-d\TH:i:sP'),
                $expires,
                $card->claim->revisionAtClaim->toString(),
            ));
        }

        if ($card->externalIssue !== null) {
            $lines[] = $this->bullet('External issue', $card->externalIssue->system . ':' . $card->externalIssue->key);
        }

        $lines[] = $this->bullet('Format version', (string) $card->formatVersion);

        foreach ($card->extensionFields as $label => $value) {
            $lines[] = $this->bullet($label, $value);
        }

        return $lines;
    }

    private function bullet(string $label, string $value): string
    {
        $singleLine = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));

        return '- **' . $label . ':** ' . $singleLine;
    }
}
