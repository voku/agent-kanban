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

        $parsed = ArgvParser::parse($tokens);
        $positional = $parsed['positional'];
        $command = $positional[0] ?? '';

        try {
            $format = OutputFormat::fromString(ArgvParser::stringOption($parsed, 'format'));
            $context = $this->contextFactory->create(
                $this->defaultRootPath,
                ArgvParser::stringOption($parsed, 'root'),
                ArgvParser::stringOption($parsed, 'config'),
            );

            return match ($command) {
                'summary'             => $this->cmdSummary($context, $format),
                'render'              => $this->cmdRender($context, $parsed, $format),
                'verify'              => $this->cmdVerify($context, $format),
                'next-pull'           => $this->cmdNextPull($context, $format),
                'lane'                => $this->cmdLane($context, $positional[1] ?? '', $format),
                'card'                => $this->cmdCard($context, $positional, $parsed, $format),
                'external-sync', 'jira-sync' => $this->cmdExternalSync($context, $parsed, $format),
                // Deprecated 0.x command aliases; see UPGRADING.md.
                'ticket', 'context'   => $this->cardShow($context, CardId::fromString($positional[1] ?? ''), $format),
                'brief'               => $this->cmdBrief($context, $positional[1] ?? '', $format),
                default               => $this->cmdUnknown($command),
            };
        } catch (AgentKanbanException $exception) {
            return $this->reportError($exception, $format ?? OutputFormat::Text);
        }
    }

    private function cmdUnknown(string $command): int
    {
        fwrite(STDERR, sprintf("Unknown command: \"%s\". Run \"agent-kanban help\" for usage.\n", $command));

        return self::EXIT_USAGE_ERROR;
    }

    private function cmdSummary(BoardContext $context, OutputFormat $format): int
    {
        $board = $this->loadBoard($context);
        $renderer = new BoardRenderer();
        $json = new JsonBoardRenderer();

        echo match ($format) {
            OutputFormat::Json               => $json->encode($json->summaryToArray($board)),
            OutputFormat::Markdown, OutputFormat::Text => $renderer->renderSummary($board) . "\n" . $renderer->renderWipHealth($board) . "\n",
        };

        return self::EXIT_OK;
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cmdRender(BoardContext $context, array $parsed, OutputFormat $format): int
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

        if ($format === OutputFormat::Json) {
            $query = new BoardQueryService($board);
            $cards = $options->search !== null ? $query->search($options->search) : $board->cards->all();
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($cards));

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderFiltered($board, $options);

        return self::EXIT_OK;
    }

    private function cmdVerify(BoardContext $context, OutputFormat $format): int
    {
        $lenient = $context->repository->loadAllLenient();
        $board = new Board($context->config, $lenient->cards, $context->repository->resolveCardDirectory() ?? $context->config->cardDirectory);
        $verificationContext = $this->buildVerificationContext($context);

        $report = (new BoardVerifier())->verify($board, $lenient->failures, $verificationContext);

        if ($format === OutputFormat::Json) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->verificationReportToArray($report));
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

    private function cmdNextPull(BoardContext $context, OutputFormat $format): int
    {
        $board = $this->loadBoard($context);
        $candidates = (new BoardQueryService($board))->nextPullCandidates();

        if ($format === OutputFormat::Json) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($candidates));

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderNextPullCandidates($board) . "\n";

        return self::EXIT_OK;
    }

    private function cmdLane(BoardContext $context, string $laneName, OutputFormat $format): int
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

        if ($format === OutputFormat::Json) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardsToEnvelope($cards));

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderLane($board, $lane) . "\n";

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $positional
     * @param ParsedArgs $parsed
     */
    private function cmdCard(BoardContext $context, array $positional, array $parsed, OutputFormat $format): int
    {
        $subcommand = $positional[1] ?? '';
        $cardIdValue = $positional[2] ?? '';

        if ($subcommand === '' || $cardIdValue === '') {
            fwrite(STDERR, "Usage: agent-kanban card <show|create|update|move|claim|release|archive|restore> <ID> [options]\n");

            return self::EXIT_USAGE_ERROR;
        }

        $id = CardId::fromString($cardIdValue);
        $dryRun = ArgvParser::boolOption($parsed, 'dry-run');
        $expectedRevisionValue = ArgvParser::stringOption($parsed, 'expected-revision');
        $expectedRevision = $expectedRevisionValue !== null ? CardRevision::fromHex($expectedRevisionValue) : null;

        return match ($subcommand) {
            'show'    => $this->cardShow($context, $id, $format),
            'create'  => $this->cardCreate($context, $id, $parsed, $dryRun, $format),
            'update'  => $this->cardUpdate($context, $id, $parsed, $expectedRevision, $dryRun, $format),
            'move'    => $this->cardMove($context, $id, $parsed, $expectedRevision, $dryRun, $format),
            'claim'   => $this->cardClaim($context, $id, $parsed, $expectedRevision, $dryRun, $format),
            'release' => $this->cardRelease($context, $id, $parsed, $expectedRevision, $dryRun, $format),
            'archive' => $this->cardArchive($context, $id, $expectedRevision, $dryRun, $format),
            'restore' => $this->cardRestore($context, $id, $expectedRevision, $dryRun, $format),
            default   => $this->cmdUnknown('card ' . $subcommand),
        };
    }

    private function cmdBrief(BoardContext $context, string $cardIdValue, OutputFormat $format): int
    {
        if ($cardIdValue === '') {
            fwrite(STDERR, "Usage: agent-kanban brief <ID>\n");

            return self::EXIT_USAGE_ERROR;
        }

        $card = $context->repository->load(CardId::fromString($cardIdValue));
        if ($card->taskBrief === '') {
            fwrite(STDERR, sprintf("No Agent Task Brief found for %s.\n", $card->id));

            return self::EXIT_NOT_FOUND;
        }

        if ($format === OutputFormat::Json) {
            echo (new JsonBoardRenderer())->encode([
                'schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION,
                'type'          => 'card-brief',
                'generatedAt'   => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'cardId'        => $card->id->toString(),
                'taskBrief'     => $card->taskBrief,
            ]);

            return self::EXIT_OK;
        }

        echo $card->taskBrief . "\n";

        return self::EXIT_OK;
    }

    private function cardShow(BoardContext $context, CardId $id, OutputFormat $format): int
    {
        $card = $context->repository->load($id);

        if ($format === OutputFormat::Json) {
            $json = new JsonBoardRenderer();
            echo $json->encode($json->cardToEnvelope($card));

            return self::EXIT_OK;
        }

        echo (new BoardRenderer())->renderCard($card) . "\n";

        return self::EXIT_OK;
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardCreate(BoardContext $context, CardId $id, array $parsed, bool $dryRun, OutputFormat $format): int
    {
        $laneValue = ArgvParser::stringOption($parsed, 'lane', 'BACKLOG') ?? 'BACKLOG';
        $statusValue = ArgvParser::stringOption($parsed, 'status', '') ?? '';
        $title = ArgvParser::stringOption($parsed, 'title', '') ?? '';
        $summary = ArgvParser::stringOption($parsed, 'summary', '') ?? '';

        $service = $this->mutationService($context);
        $result = $service->create(
            $id,
            Lane::fromString($laneValue),
            CardStatus::fromString($statusValue),
            $title,
            $summary,
            $dryRun,
        );

        return $this->reportMutation($result, $format);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardUpdate(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
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
            priority: $this->intOptionOrNull($parsed, 'priority'),
            wave: ArgvParser::stringOption($parsed, 'wave'),
            taskBrief: ArgvParser::stringOption($parsed, 'brief'),
            handoffNotes: ArgvParser::stringOption($parsed, 'handoff'),
            expectedRevision: $expectedRevision,
            dryRun: $dryRun,
        );

        return $this->reportMutation($result, $format);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardMove(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
    {
        $to = ArgvParser::stringOption($parsed, 'to');
        if ($to === null) {
            throw new ValidationException('card move requires --to=<LANE>.', field: 'to', cardId: $id->toString());
        }

        $service = $this->mutationService($context);
        $result = $service->move($id, Lane::fromString($to), ArgvParser::stringOption($parsed, 'actor'), $expectedRevision, $dryRun);

        return $this->reportMutation($result, $format);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardClaim(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
    {
        $actor = ArgvParser::stringOption($parsed, 'by');
        if ($actor === null) {
            throw new ValidationException('card claim requires --by=<actor>.', field: 'by', cardId: $id->toString());
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

        return $this->reportMutation($result, $format);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cardRelease(BoardContext $context, CardId $id, array $parsed, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
    {
        $actor = ArgvParser::stringOption($parsed, 'by');
        if ($actor === null) {
            throw new ValidationException('card release requires --by=<actor>.', field: 'by', cardId: $id->toString());
        }

        $service = $this->mutationService($context);
        $result = $service->release($id, $actor, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $format);
    }

    private function cardArchive(BoardContext $context, CardId $id, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
    {
        $service = $this->mutationService($context);
        $result = $service->archive($id, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $format);
    }

    private function cardRestore(BoardContext $context, CardId $id, ?CardRevision $expectedRevision, bool $dryRun, OutputFormat $format): int
    {
        $service = $this->mutationService($context);
        $result = $service->restore($id, $expectedRevision, $dryRun);

        return $this->reportMutation($result, $format);
    }

    /**
     * @param ParsedArgs $parsed
     */
    private function cmdExternalSync(BoardContext $context, array $parsed, OutputFormat $format): int
    {
        $providerClass = ArgvParser::stringOption($parsed, 'provider-class');
        if ($providerClass === null) {
            throw new ConfigurationException('external-sync requires --provider-class=<Fully\\Qualified\\ClassName> implementing ExternalIssueProvider.');
        }

        if (!class_exists($providerClass)) {
            throw new ConfigurationException(sprintf('Class "%s" does not exist or is not autoloadable.', $providerClass));
        }

        $provider = new $providerClass();
        if (!$provider instanceof ExternalIssueProvider) {
            throw new ConfigurationException(sprintf('Class "%s" does not implement ExternalIssueProvider.', $providerClass));
        }

        $query = ArgvParser::stringOption($parsed, 'query') ?? ArgvParser::stringOption($parsed, 'jql', '') ?? '';

        try {
            $issues = $provider->fetchActiveIssues($query);
        } catch (Throwable $exception) {
            throw new ExternalProviderException($exception->getMessage(), $provider->systemName());
        }

        $board = $this->loadBoard($context);
        $drift = (new ExternalIssueComparator())->compare($board->cards, $issues, $context->config);

        if ($format === OutputFormat::Json) {
            $entries = array_map(static fn ($entry): array => $entry->toArray(), $drift->entries);
            echo (new JsonBoardRenderer())->encode([
                'schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION,
                'type'          => 'external-issue-drift',
                'generatedAt'   => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'system'        => $provider->systemName(),
                'count'         => count($entries),
                'entries'       => $entries,
            ]);

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
        $metadata = BoardMetadata::fromFile($context->rootPath . '/todo/board.md');

        return new Board(
            $context->config,
            $cards,
            $context->repository->resolveCardDirectory() ?? $context->config->cardDirectory,
            $metadata->doneCount,
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

        $indexPath = $context->rootPath . '/TODO.md';
        $indexContent = is_file($indexPath) ? file_get_contents($indexPath) : false;

        return new BoardVerificationContext(
            archivedCardIds: $archivedCardIds,
            bothCardDirectoriesExist: $preferredExists && $legacyExists,
            boardMetadata: BoardMetadata::fromFile($context->rootPath . '/todo/board.md'),
            indexContent: $indexContent === false ? null : $indexContent,
            cardDirectory: $context->repository->resolveCardDirectory(),
        );
    }

    private function mutationService(BoardContext $context): CardMutationService
    {
        return new CardMutationService($context->rootPath, $context->config, $context->repository);
    }

    private function reportMutation(MutationResult $result, OutputFormat $format): int
    {
        if ($format === OutputFormat::Json) {
            echo (new JsonBoardRenderer())->encode(array_merge(
                ['schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION, 'type' => 'mutation-result', 'generatedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP')],
                $result->toArray(),
            ));

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
     */
    private function intOptionOrNull(array $parsed, string $name): ?int
    {
        $value = ArgvParser::stringOption($parsed, $name);
        if ($value === null || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
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

    private function reportError(AgentKanbanException $exception, OutputFormat $format): int
    {
        $exitCode = match (true) {
            $exception instanceof NotFoundException           => self::EXIT_NOT_FOUND,
            $exception instanceof ConflictException            => self::EXIT_CONFLICT,
            $exception instanceof ConfigurationException       => self::EXIT_CONFIGURATION_ERROR,
            $exception instanceof ExternalProviderException    => self::EXIT_EXTERNAL_PROVIDER_ERROR,
            default                                             => self::EXIT_USAGE_ERROR,
        };

        if ($format === OutputFormat::Json) {
            echo (new JsonBoardRenderer())->encode(array_merge(
                [
                    'schemaVersion' => JsonBoardRenderer::SCHEMA_VERSION,
                    'type'          => $exception instanceof ConflictException ? 'conflict-error' : 'error',
                    'generatedAt'   => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                    'exception'     => (new \ReflectionClass($exception))->getShortName(),
                    'message'       => $this->sanitizeForOutput($exception->getMessage()),
                ],
                $this->exceptionContext($exception),
            ));

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

            Commands:
              help                                Show this help and exit 0.
              summary                             Board summary (lane counts, WIP health).
              render [filters]                    Render lanes with optional filters.
              verify                              Verify board integrity; see docs/cli.md for exit codes.
              next-pull                           Cards with a configured pull priority, ranked.
              lane <LANE>                         Cards in one lane.
              card show <ID>                      Show one card.
              card create <ID> --title=... [--lane=] [--status=] [--summary=]
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

            Global options:
              --format=text|markdown|json   Output format (default: text).
              --dry-run                     Preview a mutation without writing.
              --expected-revision=<sha256>  Optimistic-concurrency check for mutations.
              --root=<path>                 Board root (default: current directory).
              --config=<path>               Explicit BoardConfig JSON file.

            Exit codes: 0 ok, 1 usage/validation error, 2 not found, 3 conflict,
            4 verification failed, 5 configuration error, 6 external provider error.
            See docs/cli.md for the full reference.

            HELP;
    }
}
