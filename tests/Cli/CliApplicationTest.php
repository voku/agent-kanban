<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Cli;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests that actually invoke `bin/agent-kanban` as a subprocess,
 * so STDOUT/STDERR separation and process exit codes are verified for real
 * — not just the in-process return value of `CliApplication::run()`.
 */
final class CliApplicationTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];
    }

    public function testHelpExitsZeroAndWritesToStdout(): void
    {
        $result = $this->runCli(['help'], $this->fixtureRoot());

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Usage: agent-kanban', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testDoubleDashHelpAndDashHAlsoExitZero(): void
    {
        self::assertSame(0, $this->runCli(['--help'], $this->fixtureRoot())['exitCode']);
        self::assertSame(0, $this->runCli(['-h'], $this->fixtureRoot())['exitCode']);
    }

    public function testNoArgumentsShowsHelp(): void
    {
        $result = $this->runCli([], $this->fixtureRoot());

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Usage: agent-kanban', $result['stdout']);
    }

    public function testStandaloneCliDefaultsToCompactWorkspace(): void
    {
        $project = sys_get_temp_dir() . '/agent_kanban_compact_default_' . bin2hex(random_bytes(6));
        mkdir($project . '/.agent-loop/todo/cards', 0o777, true);
        file_put_contents(
            $project . '/.agent-loop/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 0\n",
        );
        file_put_contents(
            $project . '/.agent-loop/todo/cards/ABC-1.md',
            "# ABC-1: Compact default\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Status:** Selected\n",
        );
        $this->tempDirs[] = $project;

        $result = $this->runCli(['summary'], $project, [], true);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('READY', $result['stdout']);
    }

    public function testUnknownCommandExitsNonZeroAndWritesToStderr(): void
    {
        $result = $this->runCli(['bogus-command'], $this->fixtureRoot());

        self::assertNotSame(0, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('Unknown command', $result['stderr']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function removedLegacyCommandProvider(): iterable
    {
        yield 'ticket' => [['ticket', 'ITPNG-999']];
        yield 'context' => [['context', 'ITPNG-999']];
        yield 'brief' => [['brief', 'ITPNG-999']];
        yield 'jira-sync' => [['jira-sync']];
    }

    /** @param list<string> $args */
    #[DataProvider('removedLegacyCommandProvider')]
    public function testRemovedLegacyCommandsAreRejectedAsUnknown(array $args): void
    {
        $result = $this->runCli($args, $this->fixtureRoot());

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('Unknown command', $result['stderr']);
    }

    public function testCommandRejectsAnOptionThatBelongsToAnotherCommand(): void
    {
        $result = $this->runCli(['summary', '--actor=test'], $this->fixtureRoot());

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('not valid for "summary"', $result['stderr']);
    }

    public function testVerifyRejectsAnOptionThatBelongsToCardCommands(): void
    {
        $result = $this->runCli(['verify', '--title=Something'], $this->fixtureRoot());

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('not valid for "verify"', $result['stderr']);
    }

    public function testSummaryTextFormat(): void
    {
        $result = $this->runCli(['summary'], $this->fixtureRoot());

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('READY', $result['stdout']);
    }

    public function testSummaryJsonFormatIsValidJson(): void
    {
        $result = $this->runCli(['summary', '--format=json'], $this->fixtureRoot());

        self::assertSame(0, $result['exitCode']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertSame('board-summary', $decoded['type']);
    }

    public function testRenderJsonFormatAppliesTheSameFiltersAsTextFormat(): void
    {
        $root = $this->emptyBoard();
        file_put_contents($root . '/todo/cards/ABC-1.md', "# ABC-1: Security card\n\n- **Ticket:** ABC-1\n- **Lane:** READY\n- **Domain:** Security\n");
        file_put_contents($root . '/todo/cards/ABC-2.md', "# ABC-2: M365 card\n\n- **Ticket:** ABC-2\n- **Lane:** READY\n- **Domain:** M365\n");

        $result = $this->runCli(['render', '--domain=Security', '--format=json'], $root);

        self::assertSame(0, $result['exitCode']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['count']);
        $cards = $decoded['cards'];
        self::assertIsArray($cards);
        $firstCard = $cards[0];
        self::assertIsArray($firstCard);
        self::assertSame('ABC-1', $firstCard['id']);
    }

    public function testVerifyPassesOnValidFixture(): void
    {
        $result = $this->runCli(['verify'], $this->fixtureRoot());

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('passed', $result['stdout']);
    }

    public function testVerifyFailsExitsNonZeroWithViolationsOnStderr(): void
    {
        $root = $this->boardWithInvalidCard();
        $result = $this->runCli(['verify'], $root);

        self::assertNotSame(0, $result['exitCode']);
        self::assertStringContainsString('failed', $result['stderr']);
    }

    public function testInvalidFormatOptionExitsWithUsageError(): void
    {
        $result = $this->runCli(['summary', '--format=yaml'], $this->fixtureRoot());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Invalid --format', $result['stderr']);
    }

    public function testCardShowMissingCardIsNotFoundExitCode(): void
    {
        $result = $this->runCli(['card', 'show', 'ITPNG-999'], $this->fixtureRoot());

        self::assertSame(2, $result['exitCode']);
    }

    public function testCardCreateUpdateMoveClaimReleaseLifecycle(): void
    {
        $root = $this->emptyBoard();

        $create = $this->runCli(['card', 'create', 'ABC-1', '--title=New', '--lane=BACKLOG', '--summary=S'], $root);
        self::assertSame(0, $create['exitCode']);
        self::assertFileExists($root . '/todo/cards/ABC-1.md');

        $dryRun = $this->runCli(['card', 'update', 'ABC-1', '--summary=Changed', '--dry-run'], $root);
        self::assertSame(0, $dryRun['exitCode']);
        self::assertStringContainsString('dry run', $dryRun['stdout']);
        self::assertStringNotContainsString('Changed', (string) file_get_contents($root . '/todo/cards/ABC-1.md'));

        $update = $this->runCli(['card', 'update', 'ABC-1', '--summary=Changed'], $root);
        self::assertSame(0, $update['exitCode']);
        self::assertStringContainsString('Changed', (string) file_get_contents($root . '/todo/cards/ABC-1.md'));

        $badMove = $this->runCli(['card', 'move', 'ABC-1', '--to=VERIFY'], $root);
        self::assertSame(1, $badMove['exitCode']);

        $move = $this->runCli(['card', 'move', 'ABC-1', '--to=READY'], $root);
        self::assertSame(0, $move['exitCode']);

        $claim = $this->runCli(['card', 'claim', 'ABC-1', '--by=codex'], $root);
        self::assertSame(0, $claim['exitCode']);

        $conflictClaim = $this->runCli(['card', 'claim', 'ABC-1', '--by=someone-else'], $root);
        self::assertSame(3, $conflictClaim['exitCode']);

        $release = $this->runCli(['card', 'release', 'ABC-1', '--by=codex'], $root);
        self::assertSame(0, $release['exitCode']);
    }

    public function testJsonMutationResultIsVersionedAndParseable(): void
    {
        $root = $this->emptyBoard();
        $result = $this->runCli(['card', 'create', 'ABC-1', '--title=T', '--lane=BACKLOG', '--format=json'], $root);

        self::assertSame(0, $result['exitCode']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertSame('mutation-result', $decoded['type']);
        self::assertSame('create', $decoded['operation']);
    }

    public function testMissingRequiredOptionIsUsageError(): void
    {
        $root = $this->emptyBoard();
        $this->runCli(['card', 'create', 'ABC-1', '--title=T', '--lane=BACKLOG'], $root);

        $result = $this->runCli(['card', 'move', 'ABC-1'], $root);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('--to', $result['stderr']);
    }

    public function testLaneCommandRejectsUnknownLane(): void
    {
        $result = $this->runCli(['lane', 'NOT_A_LANE'], $this->fixtureRoot());

        self::assertNotSame(0, $result['exitCode']);
    }

    public function testExternalSyncWithAValidProvider(): void
    {
        $result = $this->runCli(
            ['external-sync', '--provider-class=voku\\AgentKanban\\Tests\\Support\\FakeExternalIssueProvider'],
            $this->fixtureRoot(),
        );

        self::assertSame(0, $result['exitCode']);
    }

    public function testExternalSyncNeverInstantiatesAClassThatDoesNotImplementTheInterface(): void
    {
        $marker = sys_get_temp_dir() . '/agent_kanban_side_effect_' . bin2hex(random_bytes(6));
        self::assertFileDoesNotExist($marker);

        try {
            $result = $this->runCli(
                ['external-sync', '--provider-class=voku\\AgentKanban\\Tests\\Support\\NotAnExternalIssueProviderWithSideEffect'],
                $this->fixtureRoot(),
                ['AGENT_KANBAN_TEST_SIDE_EFFECT_MARKER' => $marker],
            );

            self::assertSame(5, $result['exitCode']);
            self::assertStringContainsString('does not implement ExternalIssueProvider', $result['stderr']);
            self::assertFileDoesNotExist($marker, 'the class must not be constructed before the interface check');
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testFieldsProjectsCardListsDownToTheNamedFields(): void
    {
        $root = $this->boardWithBriefs();

        $result = $this->runCli(['render', '--format=json', '--fields=lane,priority'], $root);

        self::assertSame(0, $result['exitCode']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame('card-list', $decoded['type']);
        $cards = $decoded['cards'];
        self::assertIsArray($cards);
        self::assertNotSame([], $cards);

        foreach ($cards as $card) {
            self::assertIsArray($card);
            self::assertSame(['id', 'lane', 'priority'], array_keys($card));
        }

        self::assertStringNotContainsString('unbounded brief prose', $result['stdout']);
    }

    public function testFieldsWorksForLaneNextPullAndCardShow(): void
    {
        $root = $this->boardWithBriefs();

        foreach ([['lane', 'READY'], ['next-pull'], ['card', 'show', 'ABC-1']] as $command) {
            $args = array_merge($command, ['--format=json', '--fields=lane']);
            $result = $this->runCli($args, $root);

            self::assertSame(0, $result['exitCode'], implode(' ', $command));
            $decoded = json_decode($result['stdout'], true);
            self::assertIsArray($decoded);

            if (array_key_exists('card', $decoded)) {
                $card = $decoded['card'];
            } else {
                $cards = $decoded['cards'];
                self::assertIsArray($cards);
                $card = $cards[0];
            }

            self::assertIsArray($card);
            self::assertSame(['id', 'lane'], array_keys($card), implode(' ', $command));
        }
    }

    public function testFieldsKeepsTheEnvelopeAndSchemaVersionIntact(): void
    {
        $root = $this->boardWithBriefs();

        $result = $this->runCli(['card', 'show', 'ABC-1', '--format=json', '--fields=status'], $root);

        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertSame('card', $decoded['type']);
        self::assertArrayHasKey('generatedAt', $decoded);
    }

    public function testCompactEmitsTheSameDocumentWithoutPrettyWhitespace(): void
    {
        $root = $this->boardWithBriefs();

        $pretty = $this->runCli(['summary', '--format=json'], $root);
        $compact = $this->runCli(['summary', '--format=json', '--compact'], $root);

        self::assertSame(0, $compact['exitCode']);
        self::assertStringNotContainsString("\n    ", $compact['stdout']);
        self::assertLessThan(strlen($pretty['stdout']), strlen($compact['stdout']));

        $prettyDecoded = json_decode($pretty['stdout'], true);
        $compactDecoded = json_decode($compact['stdout'], true);
        self::assertIsArray($prettyDecoded);
        self::assertIsArray($compactDecoded);
        unset($prettyDecoded['generatedAt'], $compactDecoded['generatedAt']);
        self::assertSame($prettyDecoded, $compactDecoded);
    }

    public function testCompactAppliesToEveryJsonShapeIncludingErrors(): void
    {
        $root = $this->boardWithBriefs();

        $result = $this->runCli(['card', 'show', 'ABC-404', '--format=json', '--compact'], $root);

        self::assertSame(2, $result['exitCode']);
        self::assertStringNotContainsString("\n    ", $result['stdout']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['type']);
    }

    public function testCombinedFieldsAndCompactCollapseABoardListingDramatically(): void
    {
        // The whole point of both options: an orchestrator that only ranks
        // work should not pay for every card's full task brief.
        $root = $this->boardWithBriefs();

        $full = $this->runCli(['render', '--format=json'], $root);
        $reduced = $this->runCli(['render', '--format=json', '--fields=lane,priority', '--compact'], $root);

        self::assertSame(0, $reduced['exitCode']);
        self::assertLessThan(strlen($full['stdout']) / 10, strlen($reduced['stdout']));
    }

    public function testFieldsAndCompactAreRejectedWithoutJsonFormat(): void
    {
        $root = $this->boardWithBriefs();

        $fields = $this->runCli(['render', '--fields=lane'], $root);
        self::assertSame(1, $fields['exitCode']);
        self::assertStringContainsString('--fields requires --format=json', $fields['stderr']);

        $compact = $this->runCli(['render', '--compact'], $root);
        self::assertSame(1, $compact['exitCode']);
        self::assertStringContainsString('--compact requires --format=json', $compact['stderr']);
    }

    public function testUnknownFieldNameIsRejectedRatherThanIgnored(): void
    {
        $result = $this->runCli(['render', '--format=json', '--fields=lane,nope'], $this->boardWithBriefs());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Unknown card field', $result['stdout'] . $result['stderr']);
    }

    public function testABadFieldsValueIsStillReportedAsJsonWhenJsonWasRequested(): void
    {
        // The caller asked for JSON precisely because it cannot read prose;
        // failing on the option it used to ask for smaller JSON must not
        // hand it back a plain-text error.
        $result = $this->runCli(['render', '--format=json', '--fields=nope'], $this->boardWithBriefs());

        self::assertSame(1, $result['exitCode']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['type']);
        self::assertSame(1, $decoded['schemaVersion']);
        self::assertSame('ValidationException', $decoded['exception']);
        $message = $decoded['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Unknown card field', $message);
    }

    public function testABadFieldsValueHonoursCompactForItsJsonError(): void
    {
        $result = $this->runCli(['render', '--format=json', '--compact', '--fields=nope'], $this->boardWithBriefs());

        self::assertSame(1, $result['exitCode']);
        self::assertStringNotContainsString("\n    ", $result['stdout']);
        self::assertIsArray(json_decode($result['stdout'], true));
    }

    public function testAnInvalidFormatStillFallsBackToATextError(): void
    {
        $result = $this->runCli(['render', '--format=yaml', '--fields=lane'], $this->boardWithBriefs());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Invalid --format', $result['stderr']);
    }

    public function testFieldsIsRejectedForCommandsWithoutCardOutput(): void
    {
        $result = $this->runCli(['summary', '--format=json', '--fields=lane'], $this->boardWithBriefs());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Option --fields is not valid for', $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('summary', $result['stdout'] . $result['stderr']);
    }

    /**
     * A board whose cards carry long task briefs — the field that dominates
     * full-card JSON output.
     */
    private function boardWithBriefs(): string
    {
        $root = $this->emptyBoard();

        foreach ([1, 2, 3] as $index) {
            file_put_contents(
                $root . '/todo/cards/ABC-' . $index . '.md',
                "# ABC-{$index}: Card {$index}\n\n"
                . "- **Ticket:** ABC-{$index}\n"
                . "- **Lane:** READY\n"
                . "- **Status:** Selected\n"
                . "- **Priority:** {$index}\n\n"
                . "## Agent Task Brief\n"
                . str_repeat('unbounded brief prose that an orchestrator does not need while ranking work. ', 40) . "\n",
            );
        }

        return $root;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $extraEnv
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runCli(array $args, string $cwd, array $extraEnv = [], bool $useDefaultRoot = false): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-kanban';
        if (!$useDefaultRoot) {
            $args[] = '--root=' . $cwd;
        }
        $command = array_merge(['php', $bin], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        $env = $extraEnv === [] ? null : array_merge(getenv(), $extraEnv);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($escaped, $descriptors, $pipes, $cwd, $env);
        self::assertNotFalse($process);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/project-root';
    }

    private function emptyBoard(): string
    {
        $dir = sys_get_temp_dir() . '/agent_kanban_cli_test_' . bin2hex(random_bytes(6));
        mkdir($dir . '/todo/cards', 0o777, true);
        file_put_contents($dir . '/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 0\n");
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function boardWithInvalidCard(): string
    {
        $dir = $this->emptyBoard();
        file_put_contents($dir . '/todo/cards/ABC-1.md', "# ABC-1: Broken\n\n- **Ticket:** ABC-1\n- **Lane:** NOT_A_LANE\n");

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }

        rmdir($dir);
    }
}
