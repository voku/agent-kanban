<?php

declare(strict_types=1);

namespace voku\AgentKanban\Repository;

use DateTimeImmutable;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Claim;
use voku\AgentKanban\Domain\ExternalIssueRef;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ValidationException;

/**
 * Parses one card file's raw content into an immutable {@see Card}.
 *
 * This is a pure, stateless transformation: raw bytes in, a Card (or a
 * {@see ValidationException}) out. It never touches the filesystem — that is
 * {@see MarkdownCardRepository}'s job — which keeps parsing trivially unit
 * testable against inline fixtures. See `docs/card-format.md` for the
 * normative format this class implements.
 */
final class CardParser
{
    private const array RECOGNIZED_LABELS = [
        'Ticket', 'Lane', 'Status', 'Domain', 'Assignee', 'Created', 'Updated',
        'Summary', 'Next', 'Validation', 'Priority', 'Next pull rank', 'Wave',
        'Claim', 'External issue', 'Format version',
    ];

    private const array TIMESTAMP_FORMATS = [
        'Y-m-d\TH:i:sP',
        'Y-m-d H:i:s',
        'Y-m-d',
        'd.m.Y H:i:s',
        'd.m.Y',
    ];

    public function parse(string $content, string $sourceFile, ?string $fallbackIdFromFilename = null): Card
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_contains($normalized, "\0")) {
            throw new ValidationException('Card content must not contain NUL bytes.', cardFile: $sourceFile);
        }

        $lines = explode("\n", $normalized);
        $title = $this->parseTitle($lines);
        $metadataEndIndex = $this->findMetadataEnd($lines);
        $metadata = $this->parseMetadataBullets(array_slice($lines, 0, $metadataEndIndex), $sourceFile);
        $body = implode("\n", array_slice($lines, $metadataEndIndex));

        $id = $this->resolveId($metadata, $fallbackIdFromFilename, $sourceFile);
        if ($title === null) {
            $title = $id->toString();
        }

        $lane = $this->requireLane($metadata, $sourceFile);
        $status = CardStatus::fromString($metadata['Status'] ?? '');
        $domain = $this->nullableString($metadata['Domain'] ?? null);
        $assignee = $this->nullableString($metadata['Assignee'] ?? null);

        [$createdAt, $createdAtRaw] = $this->parseTimestamp($metadata['Created'] ?? '');
        [$updatedAt, $updatedAtRaw] = $this->parseTimestamp($metadata['Updated'] ?? '');

        $priority = $this->parsePriority($metadata, $sourceFile);
        $claim = $this->parseClaim($metadata['Claim'] ?? null, $sourceFile);
        $externalIssue = $this->parseExternalIssue($metadata['External issue'] ?? null, $sourceFile);
        $formatVersion = $this->parseFormatVersion($metadata['Format version'] ?? null, $sourceFile);

        [$handoffNotes, $taskBrief, $extraSectionsRaw] = $this->parseSections($body, $id, $normalized);

        $extensionFields = [];
        foreach ($metadata as $label => $value) {
            if (!in_array($label, self::RECOGNIZED_LABELS, true)) {
                $extensionFields[$label] = $value;
            }
        }

        return new Card(
            id: $id,
            title: $title,
            lane: $lane,
            status: $status,
            domain: $domain,
            assignee: $assignee,
            createdAt: $createdAt,
            createdAtRaw: $createdAtRaw,
            updatedAt: $updatedAt,
            updatedAtRaw: $updatedAtRaw,
            summary: trim($metadata['Summary'] ?? ''),
            nextAction: trim($metadata['Next'] ?? ''),
            validation: trim($metadata['Validation'] ?? ''),
            priority: $priority,
            wave: trim($metadata['Wave'] ?? ''),
            taskBrief: $taskBrief,
            handoffNotes: $handoffNotes,
            claim: $claim,
            externalIssue: $externalIssue,
            formatVersion: $formatVersion,
            extensionFields: $extensionFields,
            extraSectionsRaw: $extraSectionsRaw,
            revision: CardRevision::fromContent($normalized),
            sourceFile: $sourceFile,
        );
    }

    /**
     * @param list<string> $lines
     */
    private function parseTitle(array $lines): ?string
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^#\s+(?:[A-Z][A-Z0-9]*-[0-9]+:\s*)?(.+)$/', $trimmed, $matches) === 1) {
                return trim($matches[1]);
            }

            return null;
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private function findMetadataEnd(array $lines): int
    {
        return BulletMetadata::findSectionBoundary($lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private function parseMetadataBullets(array $lines, string $sourceFile): array
    {
        return BulletMetadata::parseLines($lines, $sourceFile);
    }

    /**
     * @param array<string, string> $metadata
     */
    private function resolveId(array $metadata, ?string $fallbackIdFromFilename, string $sourceFile): CardId
    {
        $ticket = $metadata['Ticket'] ?? null;
        if ($ticket !== null && $ticket !== '') {
            return CardId::fromString($ticket);
        }

        if ($fallbackIdFromFilename !== null) {
            return CardId::fromString($fallbackIdFromFilename);
        }

        throw new ValidationException(
            'Card has no "Ticket" field and no identifiable filename.',
            cardFile: $sourceFile,
            field: 'Ticket',
        );
    }

    /**
     * @param array<string, string> $metadata
     */
    private function requireLane(array $metadata, string $sourceFile): Lane
    {
        $lane = $metadata['Lane'] ?? '';
        if ($lane === '') {
            throw new ValidationException('Card is missing required field "Lane".', cardFile: $sourceFile, field: 'Lane');
        }

        return Lane::fromString($lane);
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: string}
     */
    private function parseTimestamp(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [null, ''];
        }

        foreach (self::TIMESTAMP_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $trimmed);
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
            if ($parsed !== false && !$hasErrors) {
                return [$parsed, $trimmed];
            }
        }

        return [null, $trimmed];
    }

    /**
     * @param array<string, string> $metadata
     */
    private function parsePriority(array $metadata, string $sourceFile): ?int
    {
        $raw = $metadata['Priority'] ?? $metadata['Next pull rank'] ?? null;
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
            throw new ValidationException(
                sprintf('Invalid "Priority" value "%s": expected an integer.', $raw),
                cardFile: $sourceFile,
                field: 'Priority',
            );
        }

        return (int) $trimmed;
    }

    private function parseClaim(?string $raw, string $sourceFile): ?Claim
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parts = explode('|', trim($raw));
        $actor = array_shift($parts);
        if ($actor === '') {
            throw new ValidationException('Invalid "Claim" value: missing actor.', cardFile: $sourceFile, field: 'Claim');
        }

        $claimedAt = null;
        $expiresAt = null;
        $revisionHex = null;

        foreach ($parts as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            switch ($key) {
                case 'claimed':
                    $claimedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: null;

                    break;
                case 'expires':
                    $expiresAt = $value === '-' || $value === '' ? null : (DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: null);

                    break;
                case 'rev':
                    $revisionHex = $value;

                    break;
            }
        }

        if ($claimedAt === null || $revisionHex === null) {
            throw new ValidationException(
                'Invalid "Claim" value: expected "<actor>|claimed=<ISO8601>|expires=<ISO8601|->|rev=<sha256>".',
                cardFile: $sourceFile,
                field: 'Claim',
            );
        }

        return new Claim($actor, $claimedAt, $expiresAt, CardRevision::fromHex($revisionHex));
    }

    private function parseExternalIssue(?string $raw, string $sourceFile): ?ExternalIssueRef
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        $separatorPosition = strpos($trimmed, ':');
        if ($separatorPosition === false || $separatorPosition === 0 || $separatorPosition === strlen($trimmed) - 1) {
            throw new ValidationException(
                sprintf('Invalid "External issue" value "%s": expected "<system>:<key>".', $raw),
                cardFile: $sourceFile,
                field: 'External issue',
            );
        }

        return new ExternalIssueRef(
            substr($trimmed, 0, $separatorPosition),
            substr($trimmed, $separatorPosition + 1),
        );
    }

    private function parseFormatVersion(?string $raw, string $sourceFile): int
    {
        if ($raw === null || trim($raw) === '') {
            return 1;
        }

        $trimmed = trim($raw);
        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            throw new ValidationException(
                sprintf('Invalid "Format version" value "%s": expected a non-negative integer.', $raw),
                cardFile: $sourceFile,
                field: 'Format version',
            );
        }

        return (int) $trimmed;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseSections(string $body, CardId $id, string $fullContent): array
    {
        $blocks = $this->splitIntoSections($body);
        $handoffNotes = '';
        $taskBrief = '';
        $extraBlocks = [];
        $foundBrief = false;

        foreach ($blocks as $block) {
            if ($block['heading'] === 'Handoff / Context') {
                $handoffNotes = trim($block['body']);

                continue;
            }

            if ($block['heading'] === 'Agent Task Brief') {
                $taskBrief = trim($block['body']);
                $foundBrief = true;

                continue;
            }

            $extraBlocks[] = trim($block['raw']);
        }

        if (!$foundBrief) {
            $legacyBrief = $this->extractLegacyInlineBrief($fullContent, $id);
            if ($legacyBrief !== null) {
                $taskBrief = $legacyBrief;
            }
        }

        return [$handoffNotes, $taskBrief, implode("\n\n", array_filter($extraBlocks, static fn (string $b): bool => $b !== ''))];
    }

    /**
     * @return list<array{heading: string, body: string, raw: string}>
     */
    private function splitIntoSections(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $sections = [];
        $currentHeading = null;
        $currentLines = [];

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, '## ')) {
                if ($currentHeading !== null) {
                    $sections[] = $this->buildSection($currentHeading, $currentLines);
                }

                $currentHeading = trim(substr($line, 3));
                $currentLines = [];

                continue;
            }

            if ($currentHeading !== null) {
                $currentLines[] = $line;
            }
        }

        if ($currentHeading !== null) {
            $sections[] = $this->buildSection($currentHeading, $currentLines);
        }

        return $sections;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{heading: string, body: string, raw: string}
     */
    private function buildSection(string $heading, array $lines): array
    {
        return [
            'heading' => $heading,
            'body'    => implode("\n", $lines),
            'raw'     => implode("\n", array_merge(['## ' . $heading], $lines)),
        ];
    }

    private function extractLegacyInlineBrief(string $body, CardId $id): ?string
    {
        $pattern = '/^####\s*' . preg_quote($id->toString(), '/') . ':.*$/m';
        if (preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = (int) $matches[0][1];
        $next = strpos($body, "\n## ", $offset);
        $end = $next === false ? strlen($body) : $next;

        return trim(substr($body, $offset, $end - $offset));
    }
}
