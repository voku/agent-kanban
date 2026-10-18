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
            $result = $this->cli($worktree, ['card', 'create', '--generate-id', '--title=follow-up from ' . $branch, '--format=json']);
            self::assertSame(CliApplication::EXIT_OK, $result['exit'], $result['output']);
            /** @var array{card: string} $payload */
            $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
            $created[$branch] = $payload['card'];
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

    public function testExplicitDuplicateIdFailsClosed(): void
    {
        $main = $this->base . '/main';
        self::assertSame(0, $this->cli($main, ['card', 'create', 'TEST-7', '--title=first'])['exit']);
        $before = (string) file_get_contents($main . '/todo/cards/TEST-7.md');

        $duplicate = $this->cli($main, ['card', 'create', 'TEST-7', '--title=second']);

        self::assertNotSame(CliApplication::EXIT_OK, $duplicate['exit']);
        self::assertSame($before, (string) file_get_contents($main . '/todo/cards/TEST-7.md'), 'The existing card must stay untouched.');
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
