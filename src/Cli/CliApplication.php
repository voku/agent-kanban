<?php

declare(strict_types=1);

namespace voku\AgentKanban\Cli;

use DateTimeImmutable;
use Throwable;
use voku\AgentKanban\Board;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\AgentKanbanException;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Exception\ConflictException;
use voku\AgentKanban\Exception\ExternalProviderException;
use voku\AgentKanban\Exception\NotFoundException;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\ExternalIssue\ExternalIssueComparator;
use voku\AgentKanban\ExternalIssue\ExternalIssueProvider;
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Mutation\MutationResult;
use voku\AgentKanban\Query\BoardQueryService;
use voku\AgentKanban\Rendering\BoardRenderer;
use voku\AgentKanban\Rendering\JsonBoardRenderer;
use voku\AgentKanban\Rendering\RenderOptions;
use voku\AgentKanban\Repository\BoardMetadata;
use voku\AgentKanban\Verification\BoardVerificationContext;
use voku\AgentKanban\Verification\BoardVerifier;

/**
 * The `agent-kanban` command-line entry point. Every command here is a thin
 * shell around the typed services (`BoardQueryService`, `BoardRenderer`,
 * `BoardVerifier`, `CardMutationService`, ...) — no business logic lives in
 * this class itself. See `docs/cli.md` for the full command reference,
 * option list, and exit-code table.
 *
 * @phpstan-import-type ParsedArgs from ArgvParser
 */
final class CliApplication
{
    public const int EXIT_OK = 0;

    public const int EXIT_USAGE_ERROR = 1;

    public const int EXIT_NOT_FOUND = 2;

    public const int EXIT_CONFLICT = 3;

    public const int EXIT_VERIFICATION_FAILED = 4;

    public const int EXIT_CONFIGURATION_ERROR = 5;

    public const int EXIT_EXTERNAL_PROVIDER_ERROR = 6;

    /** @var list<string> */
    private const array GLOBAL_OPTIONS = ['format', 'root', 'config', 'compact', 'board'];

    /** @var array<string, list<string>> */
    private const array COMMAND_OPTIONS = [
        'summary'       => [],
        'render'        => ['lanes', 'lane', 'domain', 'assignee', 'status', 'search', 'limit', 'fields'],
        'verify'        => [],
        'next-pull'     => ['fields'],
        'lane'          => ['fields'],
        'external-sync' => ['provider-class', 'query'],
    ];

    /** @var array<string, list<string>> */
    private const array CARD_SUBCOMMAND_OPTIONS = [
        'show'    => ['fields'],
        'create'  => ['title', 'lane', 'status', 'summary', 'next', 'validation', 'brief', 'dry-run'],
        'update'  => [
            'title', 'status', 'domain', 'assignee', 'summary', 'next', 'validation',
            'priority', 'wave', 'brief', 'handoff', 'dry-run', 'expected-revision',
        ],
        'move'    => ['to', 'actor', 'dry-run', 'expected-revision'],
        'claim'   => ['by', 'expires', 'move-to-doing', 'dry-run', 'expected-revision'],
        'release' => ['by', 'dry-run', 'expected-revision'],
        'archive' => ['dry-run', 'expected-revision'],
        'restore' => ['dry-run', 'expected-revision'],
    ];

    public function __construct(
        private readonly string $defaultRootPath,
        private readonly BoardContextFactory $contextFactory = new BoardContextFactory(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $tokens = array_slice($argv, 1);

        if ($tokens === [] || $tokens[0] === 'help' || in_array('--help', $tokens, true) || in_array('-h', $tokens, true)) {
            $this->printHelp();

            return self::EXIT_OK;
        }

        $output = null;

        try {
            // Inside the boundary on purpose: ArgvParser rejects malformed
            // tokens by throwing, and those rejections have to be reported
            // through reportError() like any other, not escape as a fatal.
            $parsed = ArgvParser::parse($tokens);
            $positional = $parsed['positional'];
            $command = $positional[0] ?? '';

            $output = OutputOptions::fromArgs($parsed);
            $boardId = ArgvParser::stringOption($parsed, 'board');
            $context = $this->contextFactory->create(
                $this->defaultRootPath,
                ArgvParser::stringOption($parsed, 'root'),
                ArgvParser::stringOption($parsed, 'config'),
                $boardId,
            );

            if (array_key_exists($command, self::COMMAND_OPTIONS)) {
                $this->assertAllowedOptions($parsed, self::COMMAND_OPTIONS[$command], $command);
            }

            return match ($command) {
                'summary'       => $this->cmdSummary($context, $output),
                'render'        => $this->cmdRender($context, $parsed, $output),
                'verify'        => $this->cmdVerifyAll($parsed, $boardId, $context, $output),
                'next-pull'     => $this->cmdNextPull($context, $output),
                'lane'          => $this->cmdLane($context, $positional[1] ?? '', $output),
                'card'          => $this->cmdCard($context, $positional, $parsed, $output),
                'external-sync' => $this->cmdExternalSync($context, $parsed, $output),
                default         => $this->cmdUnknown($command),
            };
        } catch (AgentKanbanException $exception) {
            return $this->reportError($exception, $output ?? OutputOptions::errorFallbackFromTokens($tokens));
        }
    }

    private function cmdUnknown(string $command): int
    {
        fwrite(STDERR, sprintf("Unknown command: \"%s\". Run \"agent-kanban help\" for usage.\n", $command));

        return self::EXIT_USAGE_ERROR;
    }

    private function cmdSummary(BoardContext $context, OutputOptions $output): int
    {
        $board = $this->loadBoard($context);
        $renderer = new BoardRenderer();
        $json = new JsonBoardRenderer();

        echo match ($output->format) {
            OutputFormat::Json               => $json->encode($json->summaryToArray($board), $output->compact),
            OutputFormat::Markdown, OutputFormat::Text => $renderer->renderSummary($board) . "\n" . $renderer->renderWipHealth($board) . "\n",
        };

        return self::EXIT_OK;
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cmdRender(BoardContext $context, array $parsed, OutputOptions $output): int
    {
        $board = $this->loadBoard($context);
        $options = new RenderOptions(
            lanes: $this->parseLaneList(ArgvParser::stringOption($parsed, 'lanes') ?? ArgvParser::stringOption($parsed, 'lane')),
            domain: ArgvParser::stringOption($parsed, 'domain'),
            assignee: ArgvParser::stringOption($parsed, 'assignee'),
            status: ArgvParser::stringOption($parsed, 'status'),
            search: ArgvParser::stringOption($parsed, 'search'),
            limit: ArgvParser::intOption($parsed, 'limit', 0),
        );

        $renderer = new BoardRenderer();

        if ($output->isJson()) {
            $query = new BoardQueryService($board);
            $lanes = $options->lanes === [] ? $board->config->lanes : $options->lanes;

            $cards = [];
            foreach ($lanes as $lane) {
                $laneCards = $renderer->filterCards($query->byLane($lane), $options);
                $cards = array_merge($cards, $options->limit > 0 ? array_slice($laneCards, 0, $options->limit) : $laneCards);
            }

            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($cards, $output->fields), $output->compact);

            return self::EXIT_OK;
        }

        echo $renderer->renderFiltered($board, $options);

        return self::EXIT_OK;
    }

    /**
     * Verifying only the default board reported success over a fraction of the
     * configured scope: a repository with three boards got one green line and
     * two unchecked card directories. Unless a board is named explicitly, every
     * configured board is verified and the worst exit code wins.
     *
     * @param array{options: array<string, string|bool>, positional: list<string>} $parsed
     */
    private function cmdVerifyAll(array $parsed, ?string $boardId, BoardContext $context, OutputOptions $output): int
    {
        if ($boardId !== null) {
            return $this->cmdVerify($context, $output);
        }

        $contexts = $this->contextFactory->createAll(
            $this->defaultRootPath,
            ArgvParser::stringOption($parsed, 'root'),
            ArgvParser::stringOption($parsed, 'config'),
        );
        if (count($contexts) <= 1) {
            return $this->cmdVerify($context, $output);
        }

        $exit = self::EXIT_OK;
        foreach ($contexts as $id => $boardContext) {
            if (!$output->isJson()) {
                echo 'Board "' . $id . '":' . "\n";
            }
            $boardExit = $this->cmdVerify($boardContext, $output);
            if ($boardExit !== self::EXIT_OK) {
                $exit = $boardExit;
            }
        }

        return $exit;
    }

    private function cmdVerify(BoardContext $context, OutputOptions $output): int
    {
        $lenient = $context->repository->loadAllLenient();
        $board = new Board($context->config, $lenient->cards, $context->repository->resolveCardDirectory() ?? $context->config->cardDirectory);
        $verificationContext = $this->buildVerificationContext($context);

        $report = (new BoardVerifier())->verify($board, $lenient->failures, $verificationContext);

        if ($output->isJson()) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->verificationReportToArray($report), $output->compact);
        } else {
            if ($report->isValid()) {
                echo "Board verification passed.\n";
            } else {
                fwrite(STDERR, "Board verification failed.\n");
            }

            foreach ($report->violations as $violation) {
                $line = sprintf('[%s] %s: %s', strtoupper($violation->severity->value), $violation->code->value, $violation->message);
                fwrite($violation->severity->value === 'error' ? STDERR : STDOUT, $line . "\n");
            }
        }

        return $report->isValid() ? self::EXIT_OK : self::EXIT_VERIFICATION_FAILED;
    }

    private function cmdNextPull(BoardContext $context, OutputOptions $output): int
    {
        $board = $this->loadBoard($context);
        $candidates = (new BoardQueryService($board))->nextPullCandidates();

        if ($output->isJson()) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($candidates, $output->fields), $output->compact);

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderNextPullCandidates($board) . "\n";

        return self::EXIT_OK;
    }

    private function cmdLane(BoardContext $context, string $laneName, OutputOptions $output): int
    {
        if ($laneName === '') {
            fwrite(STDERR, "Usage: agent-kanban lane <LANE>\n");

            return self::EXIT_USAGE_ERROR;
        }

        $board = $this->loadBoard($context);
        $lane = Lane::fromString($laneName);
        if (!$context->config->supportsLane($lane)) {
            throw new ValidationException(
                sprintf('Unknown lane "%s". Configured lanes: %s.', $lane, implode(', ', $context->config->lanes)),
                field: 'lane',
            );
        }

        $cards = (new BoardQueryService($board))->byLane($lane);

        if ($output->isJson()) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($cards, $output->fields), $output->compact);

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderLane($board, $lane) . "\n";

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $positional
     * @param ParsedArgs $parsed
     */
    private function cmdCard(BoardContext $context, array $positional, array $parsed, OutputOptions $output): int
    {
        $subcommand = $positional[1] ?? '';
        $cardIdValue = $positional[2] ?? '';

        if ($subcommand === '' || $cardIdValue === '') {
            fwrite(STDERR, "Usage: agent-kanban card <show|create|update|move|claim|release|archive|restore> <ID> [options]\n");

            return self::EXIT_USAGE_ERROR;
        }

        if (array_key_exists($subcommand, self::CARD_SUBCOMMAND_OPTIONS)) {
            $this->assertAllowedOptions($parsed, self::CARD_SUBCOMMAND_OPTIONS[$subcommand], 'card ' . $subcommand);
        }

        $id = CardId::fromString($cardIdValue);
        $dryRun = ArgvParser::boolOption($parsed, 'dry-run');
        $expectedRevisionValue = ArgvParser::stringOption($parsed, 'expected-revision');
        $expectedRevision = $expectedRevisionValue !== null ? CardRevision::fromHex($expectedRevisionValue) : null;

        return match ($subcommand) {
            'show'    => $this->cardShow($context, $id, $output),
            'create'  => $this->cardCreate($context, $id, $parsed, $dryRun, $output),
            'update'  => $this->cardUpdate($context, $id, $parsed, $expectedRevision, $dryRun, $output),
            'move'    => $this->cardMove($context, $id, $parsed, $expectedRevision, $dryRun, $output),
            'claim'   => $this->cardClaim($context, $id, $parsed, $expectedRevision, $dryRun, $output),
            'release' => $this->cardRelease($context, $id, $parsed, $expectedRevision, $dryRun, $output),
            'archive' => $this->cardArchive($context, $id, $expectedRevision, $dryRun, $output),
            'restore' => $this->cardRestore($context, $id, $expectedRevision, $dryRun, $output),
            default   => $this->cmdUnknown('card ' . $subcommand),
        };
    }

    private function cardShow(BoardContext $context, CardId $id, OutputOptions $output): int
    {
        $card = $context->repository->load($id);

        if ($output->isJson()) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardToEnvelope($card, $output->fields), $output->compact);

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderCard($card) . "\n";

        return self::EXIT_OK;
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardCreate(BoardContext $context, CardId $id, array $parsed, bool $dryRun, OutputOptions $output): int
    {
        $laneValue = ArgvParser::stringOption($parsed, 'lane', 'BACKLOG') ?? 'BACKLOG';
        $statusValue = ArgvParser::stringOption($parsed, 'status', '') ?? '';
        $title = ArgvParser::stringOption($parsed, 'title', '') ?? '';
        $summary = ArgvParser::stringOption($parsed, 'summary', '') ?? '';
        $nextAction = ArgvParser::stringOption($parsed, 'next', '') ?? '';
        $validation = ArgvParser::stringOption($parsed, 'validation', '') ?? '';
        $taskBrief = ArgvParser::stringOption($parsed, 'brief', '') ?? '';

        $service = $this->mutationService($context);
        $result = $service->create(
            id: $id,
            lane: Lane::fromString($laneValue),
            status: CardStatus::fromString($statusValue),
            title: $title,
            summary: $summary,
            dryRun: $dryRun,
            taskBrief: $taskBrief,
            nextAction: $nextAction,
            validation: $validation,
        );

        return $this->reportMutation($result, $output);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardUpdate(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $statusValue = ArgvParser::stringOption($parsed, 'status');
        $service = $this->mutationService($context);
        $result = $service->update(
            $id,
            title: ArgvParser::stringOption($parsed, 'title'),
            status: $statusValue !== null ? CardStatus::fromString($statusValue) : null,
            domain: ArgvParser::stringOption($parsed, 'domain'),
            assignee: ArgvParser::stringOption($parsed, 'assignee'),
            summary: ArgvParser::stringOption($parsed, 'summary'),
            nextAction: ArgvParser::stringOption($parsed, 'next'),
            validation: ArgvParser::stringOption($parsed, 'validation'),
            priority: ArgvParser::intOptionOrNull($parsed, 'priority'),
            wave: ArgvParser::stringOption($parsed, 'wave'),
            taskBrief: ArgvParser::stringOption($parsed, 'brief'),
            handoffNotes: ArgvParser::stringOption($parsed, 'handoff'),
            expectedRevision: $expectedRevision,
            dryRun: $dryRun,
        );

        return $this->reportMutation($result, $output);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardMove(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $to = ArgvParser::stringOption($parsed, 'to');
        if ($to === null) {
            throw new ValidationException('card move requires --to=<LANE>.', field: 'to', cardId: $id->toString());
        }

        $service = $this->mutationService($context);
        $result = $service->move($id, Lane::fromString($to), ArgvParser::stringOption($parsed, 'actor'), $expectedRevision, $dryRun);

        return $this->reportMutation($result, $output);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardClaim(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $actor = ArgvParser::stringOption($parsed, 'by');
        if ($actor === null) {
            throw new ValidationException('card claim requires --by with an actor value.', field: 'by', cardId: $id->toString());
        }

        $expiresValue = ArgvParser::stringOption($parsed, 'expires');
        $expiresAt = $expiresValue !== null ? new DateTimeImmutable($expiresValue) : null;

        $service = $this->mutationService($context);
        $result = $service->claim(
            $id,
            $actor,
            $expiresAt,
            ArgvParser::boolOption($parsed, 'move-to-doing'),
            $expectedRevision,
            $dryRun,
        );

        return $this->reportMutation($result, $output);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardRelease(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $actor = ArgvParser::stringOption($parsed, 'by');
        if ($actor === null) {
            throw new ValidationException('card release requires --by with an actor value.', field: 'by', cardId: $id->toString());
        }

        $service = $this->mutationService($context);
        $result = $service->release($id, $actor, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $output);
    }

    private function cardArchive(BoardContext $context, CardId $id, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $service = $this->mutationService($context);
        $result = $service->archive($id, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $output);
    }

    private function cardRestore(BoardContext $context, CardId $id, ?CardRevision $expectedRevision, bool $dryRun, OutputOptions $output): int
    {
        $service = $this->mutationService($context);
        $result = $service->restore($id, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $output);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cmdExternalSync(BoardContext $context, array $parsed, OutputOptions $output): int
    {
        $providerClass = ArgvParser::stringOption($parsed, 'provider-class');
        if ($providerClass === null) {
            throw new ConfigurationException('external-sync requires --provider-class=<Fully\\Qualified\\ClassName> implementing ExternalIssueProvider.');
        }

        if (!class_exists($providerClass)) {
            throw new ConfigurationException(sprintf('Class "%s" does not exist or is not autoloadable.', $providerClass));
        }

        if (!is_subclass_of($providerClass, ExternalIssueProvider::class)) {
            throw new ConfigurationException(sprintf('Class "%s" does not implement ExternalIssueProvider.', $providerClass));
        }

        $provider = new $providerClass();

        $query = ArgvParser::stringOption($parsed, 'query', '') ?? '';

        try {
            $issues = $provider->fetchActiveIssues($query);
        } catch (Throwable $exception) {
            throw new ExternalProviderException($exception->getMessage(), $provider->systemName());
        }

        $board = $this->loadBoard($context);
        $drift = (new ExternalIssueComparator())->compare($board->cards, $issues, $context->config, $provider->systemName());

        if ($output->isJson()) {
            $entries = array_map(static fn ($entry): array => $entry->toArray(), $drift->entries);
            echo (new JsonBoardRenderer())->encode([
                'schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION,
                'type'          => 'external-issue-drift',
                'generatedAt'   => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'system'        => $provider->systemName(),
                'count'         => count($entries),
                'entries'       => $entries,
            ], $output->compact);

            return self::EXIT_OK;
        }

        if ($drift->isEmpty()) {
            echo 'No drift detected between local cards and ' . $provider->systemName() . ".\n";

            return self::EXIT_OK;
        }

        foreach ($drift->entries as $entry) {
            echo sprintf(
                "[%s] %s%s: %s -> %s\n",
                $entry->kind->value,
                $entry->externalKey,
                $entry->cardId !== null ? ' (' . $entry->cardId . ')' : '',
                $entry->localValue ?? '-',
                $entry->remoteValue ?? '-',
            );
        }

        return self::EXIT_OK;
    }

    private function loadBoard(BoardContext $context): Board
    {
        $cards = $context->repository->loadAll();

        return new Board(
            $context->config,
            $cards,
            $context->repository->resolveCardDirectory() ?? $context->config->cardDirectory,
            $context->repository->archivedCount(),
        );
    }

    private function buildVerificationContext(BoardContext $context): BoardVerificationContext
    {
        $preferredExists = is_dir($context->rootPath . '/' . $context->config->cardDirectory);
        $legacyExists = is_dir($context->rootPath . '/' . $context->config->legacyCardDirectory);

        $archivedCardIds = [];
        if ($context->config->archiveDirectory !== null) {
            $files = glob($context->rootPath . '/' . $context->config->archiveDirectory . '/*.md') ?: [];
            foreach ($files as $file) {
                if (preg_match('/^([A-Za-z][A-Za-z0-9]*-[0-9]+)\.md$/', basename($file), $matches) === 1) {
                    $archivedCardIds[] = strtoupper($matches[1]);
                }
            }
        }

        $indexPath = is_file($context->rootPath . '/board.md')
            ? $context->rootPath . '/board.md'
            : $context->rootPath . '/TODO.md';
        $indexContent = is_file($indexPath) ? file_get_contents($indexPath) : false;

        $metadataPath = is_file($context->rootPath . '/board.md')
            ? $context->rootPath . '/board.md'
            : $context->rootPath . '/todo/board.md';

        return new BoardVerificationContext(
            archivedCardIds: $archivedCardIds,
            bothCardDirectoriesExist: $preferredExists && $legacyExists,
            boardMetadata: BoardMetadata::fromFile($metadataPath),
            indexContent: $indexContent === false ? null : $indexContent,
            cardDirectory: $context->repository->resolveCardDirectory(),
        );
    }

    private function mutationService(BoardContext $context): CardMutationService
    {
        return new CardMutationService($context->rootPath, $context->config, $context->repository);
    }

    private function reportMutation(MutationResult $result, OutputOptions $output): int
    {
        if ($output->isJson()) {
            echo (new JsonBoardRenderer())->encode(array_merge(
                ['schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION, 'type' => 'mutation-result', 'generatedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')],
                $result->toArray(),
            ), $output->compact);

            return self::EXIT_OK;
        }

        echo sprintf(
            "%s %s: %s -> %s%s\n",
            $result->operation,
            $result->card->id->toString(),
            $result->previousRevision?->toString() ?? '(new)',
            $result->newRevision->toString(),
            $result->dryRun ? ' (dry run, not written)' : '',
        );

        foreach ($result->warnings as $warning) {
            fwrite(STDERR, 'WARNING: ' . $warning . "\n");
        }

        return self::EXIT_OK;
    }

    /**
     * @param ParsedArgs $parsed
     * @param list<string> $allowed
     */
    private function assertAllowedOptions(array $parsed, array $allowed, string $commandLabel): void
    {
        $permitted = array_merge(self::GLOBAL_OPTIONS, $allowed);
        foreach (array_keys($parsed['options']) as $name) {
            if (!in_array($name, $permitted, true)) {
                throw new ValidationException(sprintf('Option --%s is not valid for "%s".', $name, $commandLabel));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parseLaneList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $lanes = [];
        foreach (explode(',', $value) as $lane) {
            $trimmed = trim($lane);
            if ($trimmed !== '') {
                $lanes[] = strtoupper($trimmed);
            }
        }

        return $lanes;
    }

    private function reportError(AgentKanbanException $exception, OutputOptions $output): int
    {
        $exitCode = match (true) {
            $exception instanceof NotFoundException           => self::EXIT_NOT_FOUND,
            $exception instanceof ConflictException            => self::EXIT_CONFLICT,
            $exception instanceof ConfigurationException       => self::EXIT_CONFIGURATION_ERROR,
            $exception instanceof ExternalProviderException    => self::EXIT_EXTERNAL_PROVIDER_ERROR,
            default                                             => self::EXIT_USAGE_ERROR,
        };

        if ($output->isJson()) {
            echo (new JsonBoardRenderer())->encode(array_merge(
                [
                    'schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION,
                    'type'          => $exception instanceof ConflictException ? 'conflict-error' : 'error',
                    'generatedAt'   => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                    'exception'     => (new \ReflectionClass($exception))->getShortName(),
                    'message'       => $this->sanitizeForOutput($exception->getMessage()),
                ],
                $this->exceptionContext($exception),
            ), $output->compact);

            return $exitCode;
        }

        fwrite(STDERR, 'ERROR: ' . $this->sanitizeForOutput($exception->getMessage()) . "\n");

        return $exitCode;
    }

    /**
     * @return array<string, string|null>
     */
    private function exceptionContext(AgentKanbanException $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => ['cardId' => $exception->cardId, 'field' => $exception->field],
            $exception instanceof ConflictException => [
                'cardId'           => $exception->cardId,
                'expectedRevision' => $exception->expectedRevision,
                'actualRevision'   => $exception->actualRevision,
            ],
            $exception instanceof NotFoundException => ['cardId' => $exception->cardId],
            default => [],
        };
    }

    private function sanitizeForOutput(string $message): string
    {
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message);

        return $sanitized ?? $message;
    }

    private function printHelp(): void
    {
        $script = 'agent-kanban';
        echo <<<HELP
            Usage: {$script} <command> [options]
            Value-taking long options accept both --name=value and --name value.

            Commands:
              help                                Show this help and exit 0.
              summary                             Board summary (lane counts, WIP health).
              render [filters]                    Render lanes with optional filters.
              verify                              Verify board integrity; see docs/cli.md for exit codes.
              next-pull                           Cards with a configured pull priority, ranked.
              lane <LANE>                         Cards in one lane.
              card show <ID>                      Show one card.
              card create <ID> --title=... [--lane=] [--status=] [--summary=] [--next=] [--validation=] [--brief=]
              card update <ID> [--title=] [--status=] [--domain=] [--assignee=] [--summary=]
                                                   [--next=] [--validation=] [--priority=] [--wave=]
                                                   [--brief=] [--handoff=]
              card move <ID> --to=<LANE> [--actor=]
              card claim <ID> --by=<actor> [--expires=<ISO8601>] [--move-to-doing]
              card release <ID> --by=<actor>
              card archive <ID>
              card restore <ID>
              external-sync --provider-class=<FQCN> [--query=...]

            Render filters:
              --lanes=A,B  --domain=  --assignee=  --status=  --search=  --limit=N

            JSON output size (render, lane, next-pull, card show):
              --fields=a,b,c                Emit only these card fields ("id" is
                                            always included). Requires --format=json.
              --compact                     Drop pretty-print whitespace.
                                            Requires --format=json.

            Global options:
              --format=text|markdown|json   Output format (default: text).
              --dry-run                     Preview a mutation without writing.
              --expected-revision=<sha256>  Optimistic-concurrency check for mutations.
              --root=<path>                 Board root (default: current directory).
              --config=<path>               Explicit BoardConfig JSON file.
              --board=<id>                  Board to act on when several are configured.
                                            Without it, verify covers every board.

            Exit codes: 0 ok, 1 usage/validation error, 2 not found, 3 conflict,
            4 verification failed, 5 configuration error, 6 external provider error.
            See docs/cli.md for the full reference.

            HELP;
    }
}
