<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Config;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ConfigurationException;

final class BoardConfigTest extends TestCase
{
    public function testDefaultsMatchDocumentedWorkflow(): void
    {
        $config = BoardConfig::default('ABC');

        self::assertSame(['BACKLOG', 'READY', 'DOING', 'VERIFY', 'BLOCKED'], $config->lanes);
        self::assertSame('todo/cards', $config->cardDirectory);
        self::assertSame('todo/jira', $config->legacyCardDirectory);
        self::assertNull($config->archiveDirectory);
        self::assertSame(1, $config->formatVersion);
        self::assertTrue($config->supportsLane(Lane::fromString('READY')));
        self::assertFalse($config->supportsLane(Lane::fromString('DONE')));
    }

    public function testFromArrayHonorsOverrides(): void
    {
        $config = BoardConfig::fromArray([
            'projectPrefix' => 'XYZ',
            'lanes'         => ['TODO', 'DOING', 'DONE_LANE'],
            'wipLimits'     => ['DOING' => 2],
        ]);

        self::assertSame('XYZ', $config->projectPrefix);
        self::assertSame(['TODO', 'DOING', 'DONE_LANE'], $config->lanes);
        self::assertSame(2, $config->wipLimits['DOING']);
    }

    public function testFromArrayRequiresProjectPrefix(): void
    {
        $this->expectException(ConfigurationException::class);
        BoardConfig::fromArray(['lanes' => ['A']]);
    }

    public function testRejectsInvalidPrefix(): void
    {
        $this->expectException(ConfigurationException::class);
        BoardConfig::default('not-valid');
    }

    public function testRejectsEmptyLanes(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: []);
    }

    public function testRejectsDuplicateLanes(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY', 'ready']);
    }

    public function testRejectsStatusToLaneReferencingUnknownLane(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY'], statusToLane: ['DOING' => ['In Progress']]);
    }

    public function testRejectsRequiredFieldsReferencingUnknownLane(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY'], requiredFieldsByLane: ['DOING' => ['summary']]);
    }

    public function testRejectsWipLimitReferencingUnknownLane(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY'], requiredFieldsByLane: [], wipLimits: ['READY,DOING' => 3]);
    }

    public function testRejectsNegativeWipLimit(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY'], requiredFieldsByLane: [], wipLimits: ['READY' => -1]);
    }

    public function testRejectsTransitionReferencingUnknownLane(): void
    {
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['READY'], requiredFieldsByLane: [], transitions: ['READY' => ['DOING']]);
    }

    public function testRejectsDoneAsATransitionTarget(): void
    {
        // "DONE" is not a lane and is never written to a card file; leaving
        // the active board is the separate archive() mutation, not a lane
        // transition. See docs/concurrency.md.
        $this->expectException(ConfigurationException::class);
        new BoardConfig('ABC', lanes: ['VERIFY'], requiredFieldsByLane: [], transitions: ['VERIFY' => ['DONE']]);
    }

    public function testDefaultTransitionsDoNotApplyWhenLanesAddAnExtraEntry(): void
    {
        // Default transitions are documented to apply "only if `$lanes` is
        // exactly the default lane set" — an extra lane (even alongside all
        // five defaults) must not silently inherit DEFAULT_TRANSITIONS,
        // since the extra lane itself would then have no entry at all.
        $config = new BoardConfig(
            'ABC',
            lanes: ['BACKLOG', 'READY', 'DOING', 'VERIFY', 'BLOCKED', 'REVIEW'],
            requiredFieldsByLane: [],
        );

        self::assertSame([], $config->transitions);
    }

    public function testFromJsonFileNotFoundThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        BoardConfig::fromJsonFile('/nonexistent/path/config.json');
    }

    public function testFromJsonFileRoundTrips(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kanban_config_');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode(['projectPrefix' => 'ZZZ']));

        try {
            $config = BoardConfig::fromJsonFile($path);
            self::assertSame('ZZZ', $config->projectPrefix);
        } finally {
            unlink($path);
        }
    }

    public function testToArrayIsSymmetricWithFromArray(): void
    {
        $config = BoardConfig::default('ABC');
        $roundTripped = BoardConfig::fromArray($config->toArray());

        self::assertSame($config->toArray(), $roundTripped->toArray());
    }
}
