<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Cli\CliApplication;

/**
 * Two independent Git branches create a follow-up card from the same base board
 * through the supported owner CLI; merging them must neither collide nor change
 * either task's identity.
 */
final class GeneratedCardIdBranchMergeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v git')) === '') {
            self::markTestSkipped('git is required for the branch merge regression.');
        }
        $this->base = sys_get_temp_dir() . '/agent-kanban-branch-merge-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/main/todo/cards', 0o775, true);
        file_put_contents($this->base . '/main/todo/kanban.config.json', json_encode(['projectPrefix' => 'TEST'], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testIndependentBranchesMergeWithDistinctStableIds(): void
    {
        $main = $this->base . '/main';
        $this->git($main, 'init -q -b main');
        foreach (['TEST-1', 'TEST-2'] as $existing) {
            self::assertSame(0, $this->cli($main, ['card', 'create', $existing, '--title=base ' . $existing])['exit']);
        }
        $this->git($main, 'add -A');
        $this->git($main, 'commit -q -m base');
        $this->git($main, 'worktree add -q -b branch-a ' . escapeshellarg($this->base . '/a'));
        $this->git($main, 'worktree add -q -b branch-b ' . escapeshellarg($this->base . '/b'));

        $created = [];
        foreach (['a', 'b'] as $branch) {
            $worktree = $this->base . '/' . $branch;
            $result = $this->cliInNewProcess($worktree, ['card', 'create', '--title=follow-up from ' . $branch, '--format=json']);
            self::assertSame(CliApplication::EXIT_OK, $result['exit'], $result['output']);
            /** @var array{card: string} $payload */
            $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
            $created[$branch] = $payload['card'];
            $number = (int) substr($payload['card'], strlen('TEST-'));
            self::assertSame(
                (int) hexdec(substr(hash('sha256', 'branch-' . $branch), 0, 5)),
                $number >> 43,
                'The numeric namespace must come from the actual worktree branch.',
            );
            $this->git($worktree, 'add -A');
            $this->git($worktree, 'commit -q -m ' . escapeshellarg('follow-up ' . $branch));
        }

        self::assertNotSame($created['a'], $created['b'], 'Independent branches must not share an ID.');
        self::assertDoesNotMatchRegularExpression('/^TEST-[12]$/', $created['a']);

        $this->git($main, 'merge -q --no-edit branch-a');
        [$status, $output] = $this->git($main, 'merge --no-edit branch-b');
        self::assertSame(0, $status, 'Merging independent generated IDs must not conflict: ' . $output);

        foreach ($created as $branch => $id) {
            self::assertFileExists($main . '/todo/cards/' . $id . '.md', "Card {$id} from branch {$branch} must exist after the merge.");
            $show = $this->cli($main, ['card', 'show', $id, '--format=json']);
            self::assertSame(CliApplication::EXIT_OK, $show['exit'], $show['output']);
            self::assertStringContainsString('follow-up from ' . $branch, $show['output'], 'Each ID must still address its own task.');
        }
        foreach (['TEST-1', 'TEST-2'] as $legacy) {
            self::assertSame(CliApplication::EXIT_OK, $this->cli($main, ['card', 'show', $legacy])['exit'], 'Existing numeric IDs keep working.');
        }
        $verify = $this->cli($main, ['verify']);
        self::assertSame(CliApplication::EXIT_OK, $verify['exit'], $verify['output']);
    }

    public function testDetachedHeadStillGeneratesValidNumericCards(): void
    {
        $main = $this->base . '/main';
        self::assertSame(0, $this->git($main, 'init -q -b main')[0]);
        self::assertSame(0, $this->git($main, 'commit -q --allow-empty -m initial')[0]);
        self::assertSame(0, $this->git($main, 'checkout -q --detach HEAD')[0]);

        $result = $this->cliInNewProcess($main, ['card', 'create', '--title=detached', '--format=json']);

        self::assertSame(CliApplication::EXIT_OK, $result['exit'], $result['output']);
        /** @var array{card: string} $payload */
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^TEST-[1-9][0-9]*$/', $payload['card']);
        self::assertFileExists($main . '/todo/cards/' . $payload['card'] . '.md');
        self::assertSame(CliApplication::EXIT_OK, $this->cli($main, ['card', 'show', $payload['card']])['exit']);
    }

    public function testExplicitGenerateIdFlagRemainsSupported(): void
    {
        $main = $this->base . '/main';
        $result = $this->cli($main, ['card', 'create', '--generate-id', '--title=Explicitly generated', '--format=json']);

        self::assertSame(CliApplication::EXIT_OK, $result['exit'], $result['output']);
        /** @var array{card: string} $payload */
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^TEST-[1-9][0-9]*$/', $payload['card']);
        self::assertFileExists($main . '/todo/cards/' . $payload['card'] . '.md');
        self::assertStringContainsString('Explicitly generated', (string) file_get_contents($main . '/todo/cards/' . $payload['card'] . '.md'));
    }

    public function testExplicitDuplicateIdFailsClosed(): void
    {
        $main = $this->base . '/main';
        self::assertSame(0, $this->cli($main, ['card', 'create', 'TEST-7', '--title=first'])['exit']);
        $before = (string) file_get_contents($main . '/todo/cards/TEST-7.md');

        $duplicate = $this->cli($main, ['card', 'create', 'TEST-7', '--title=second']);

        self::assertNotSame(CliApplication::EXIT_OK, $duplicate['exit']);
        self::assertSame($before, (string) file_get_contents($main . '/todo/cards/TEST-7.md'), 'The existing card must stay untouched.');
    }

    public function testExplicitlyEmptyIdIsRejectedInsteadOfCreatingACard(): void
    {
        $main = $this->base . '/main';
        $before = glob($main . '/todo/cards/*.md');
        $result = $this->cli($main, ['card', 'create', '', '--title=Must not be created', '--format=json']);

        self::assertSame(CliApplication::EXIT_USAGE_ERROR, $result['exit']);
        self::assertSame($before, glob($main . '/todo/cards/*.md'));
    }

    public function testExplicitIdAndGenerateIdTogetherAreRejected(): void
    {
        $main = $this->base . '/main';
        $result = $this->cli($main, ['card', 'create', 'TEST-9', '--generate-id', '--title=x']);

        self::assertSame(CliApplication::EXIT_USAGE_ERROR, $result['exit']);
        self::assertFileDoesNotExist($main . '/todo/cards/TEST-9.md');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exit: int, output: string}
     */
    private function cli(string $root, array $arguments): array
    {
        ob_start();
        $exit = (new CliApplication($root))->run(['agent-kanban', ...$arguments]);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    /**
     * Proves that each independent Git worktree uses its own real CLI process.
     *
     * @param list<string> $arguments
     *
     * @return array{exit: int, output: string}
     */
    private function cliInNewProcess(string $root, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/agent-kanban', '--root=' . $root, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => (string) $stdout . ($exit === 0 ? '' : (string) $stderr)];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function git(string $directory, string $arguments): array
    {
        $output = [];
        exec(sprintf(
            'git -C %s -c user.email=t@example.test -c user.name=test %s 2>&1',
            escapeshellarg($directory),
            $arguments,
        ), $output, $status);

        return [$status, implode("\n", $output)];
    }
}
