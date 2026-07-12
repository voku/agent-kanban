<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Verification;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Board;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardCollection;
use voku\AgentKanban\Repository\CardLoadFailure;
use voku\AgentKanban\Tests\Support\CardFactory;
use voku\AgentKanban\Verification\BoardVerificationContext;
use voku\AgentKanban\Verification\BoardVerifier;
use voku\AgentKanban\Verification\ViolationCode;

final class BoardVerifierTest extends TestCase
{
    public function testValidBoardHasNoViolations(): void
    {
        $config = BoardConfig::default('ABC');
        $board = new Board($config, CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'READY', taskBrief: 'Brief'),
        ]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertTrue($report->isValid());
        self::assertSame([], $report->violations);
    }

    public function testDetectsUnsupportedLane(): void
    {
        $config = new BoardConfig('ABC', lanes: ['BACKLOG', 'READY'], requiredFieldsByLane: []);
        $card = CardFactory::make('ABC-1', lane: 'DOING');
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertFalse($report->isValid());
        self::assertSame(ViolationCode::UnsupportedLane, $report->errors()[0]->code);
    }

    public function testDetectsInvalidProjectPrefix(): void
    {
        $config = BoardConfig::default('ABC');
        $card = CardFactory::make('XYZ-1', lane: 'BACKLOG');
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::InvalidProjectPrefix);
    }

    public function testDetectsInvalidFilename(): void
    {
        $config = BoardConfig::default('ABC');
        $card = CardFactory::make('ABC-1', lane: 'BACKLOG', sourceFile: 'todo/cards/wrong-name.md');
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::InvalidFilename);
    }

    public function testDetectsInvalidStatusLaneMapping(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: [], statusToLane: ['READY' => ['Selected']]);
        $card = CardFactory::make('ABC-1', lane: 'READY', status: 'Bogus');
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::InvalidStatusLaneMapping);
    }

    public function testDetectsMissingTaskBrief(): void
    {
        $config = BoardConfig::default('ABC');
        $card = CardFactory::make('ABC-1', lane: 'READY', taskBrief: '');
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::MissingTaskBrief);
    }

    public function testDetectsMissingRequiredField(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: ['READY' => ['assignee']]);
        $card = CardFactory::make('ABC-1', lane: 'READY', taskBrief: 'ok', assignee: null);
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::MissingRequiredField);
    }

    public function testDetectsInvalidWipCount(): void
    {
        $config = new BoardConfig('ABC', requiredFieldsByLane: [], wipLimits: ['DOING' => 1]);
        $board = new Board($config, CardCollection::fromArray([
            CardFactory::make('ABC-1', lane: 'DOING'),
            CardFactory::make('ABC-2', lane: 'DOING'),
        ]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::InvalidWipCount);
    }

    public function testDetectsInvalidClaimOrdering(): void
    {
        $config = BoardConfig::default('ABC');
        $claim = new \voku\AgentKanban\Domain\Claim(
            'codex',
            new \DateTimeImmutable('2026-01-02'),
            new \DateTimeImmutable('2026-01-01'),
            \voku\AgentKanban\Domain\CardRevision::fromContent('x'),
        );
        $card = CardFactory::make('ABC-1', lane: 'DOING', claim: $claim);
        $board = new Board($config, CardCollection::fromArray([$card]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::InvalidClaim);
    }

    public function testDetectsIncompatibleAndStaleFormatVersion(): void
    {
        $config = BoardConfig::default('ABC');
        $future = CardFactory::make('ABC-1', lane: 'BACKLOG', formatVersion: 99);
        $stale = CardFactory::make('ABC-2', lane: 'BACKLOG', formatVersion: 0);
        $board = new Board($config, CardCollection::fromArray([$future, $stale]), 'todo/cards');

        $report = (new BoardVerifier())->verify($board);

        self::assertContainsViolation($report, ViolationCode::IncompatibleFormatVersion);
        self::assertContainsViolation($report, ViolationCode::StaleFormatVersion);
    }

    public function testLoadFailuresBecomeViolations(): void
    {
        $config = BoardConfig::default('ABC');
        $board = new Board($config, CardCollection::empty(), 'todo/cards');
        $failures = [new CardLoadFailure('todo/cards/ABC-1.md', 'Duplicate metadata field "Lane".')];

        $report = (new BoardVerifier())->verify($board, $failures);

        self::assertContainsViolation($report, ViolationCode::DuplicateMetadataField);
    }

    public function testSourceDirectoryAmbiguityIsWarningOnly(): void
    {
        $config = BoardConfig::default('ABC');
        $board = new Board($config, CardCollection::empty(), 'todo/cards');
        $context = new BoardVerificationContext(bothCardDirectoriesExist: true);

        $report = (new BoardVerifier())->verify($board, [], $context);

        self::assertTrue($report->isValid(), 'ambiguity is a warning, not an error');
        self::assertContainsViolation($report, ViolationCode::SourceDirectoryAmbiguity);
    }

    public function testArchiveConflictDetected(): void
    {
        $config = BoardConfig::default('ABC');
        $board = new Board($config, CardCollection::fromArray([CardFactory::make('ABC-1', lane: 'BACKLOG')]), 'todo/cards');
        $context = new BoardVerificationContext(archivedCardIds: ['ABC-1']);

        $report = (new BoardVerifier())->verify($board, [], $context);

        self::assertContainsViolation($report, ViolationCode::ArchiveConflict);
    }

    public function testBoardMetadataInconsistencyDetected(): void
    {
        $config = BoardConfig::default('ABC');
        $board = new Board($config, CardCollection::empty(), 'todo/cards');
        $metadata = new \voku\AgentKanban\Repository\BoardMetadata(0, 'OTHER', null);
        $context = new BoardVerificationContext(boardMetadata: $metadata);

        $report = (new BoardVerifier())->verify($board, [], $context);

        self::assertContainsViolation($report, ViolationCode::BoardMetadataInconsistency);
    }

    private static function assertContainsViolation(\voku\AgentKanban\Verification\VerificationReport $report, ViolationCode $code): void
    {
        $codes = array_map(static fn ($violation): string => $violation->code->value, $report->violations);

        self::assertContains(
            $code->value,
            $codes,
            sprintf('Expected a violation with code "%s", got: %s', $code->value, implode(', ', $codes)),
        );
    }
}
