<?php

declare(strict_types=1);

namespace voku\AgentKanban\Config;

use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\ConfigurationException;

/**
 * Board-level policy: everything that is host-specific and must never be a
 * hard-coded engine invariant (project prefix, lanes, status mapping, WIP
 * limits, required fields, transitions, format version, archive directory,
 * optional external-issue system name).
 *
 * Deliberately small. Host projects that need more (Jira credentials,
 * German status vocabularies, Docker validation commands, ...) put that in
 * their own configuration or documentation, never in this class.
 *
 * @phpstan-type TransitionMap array<string, list<string>>
 * @phpstan-type RequiredFieldsMap array<string, list<string>>
 * @phpstan-type WipLimitMap array<string, int>
 * @phpstan-type StatusToLaneMap array<string, list<string>>
 */
final readonly class BoardConfig
{
    public const string PREFERRED_CARD_DIRECTORY = 'todo/cards';

    public const string LEGACY_CARD_DIRECTORY = 'todo/jira';

    /**
     * @var list<string>
     */
    public const array DEFAULT_LANES = ['BACKLOG', 'READY', 'DOING', 'VERIFY', 'BLOCKED'];

    /**
     * @var TransitionMap
     */
    public const array DEFAULT_TRANSITIONS = [
        'BACKLOG' => ['READY'],
        'READY'   => ['DOING', 'BLOCKED'],
        'DOING'   => ['VERIFY', 'BLOCKED'],
        'VERIFY'  => ['DOING'],
        'BLOCKED' => ['READY', 'DOING', 'BACKLOG'],
    ];

    public const int CURRENT_FORMAT_VERSION = 1;

    /**
     * @var RequiredFieldsMap
     */
    public readonly array $requiredFieldsByLane;

    /**
     * @var TransitionMap
     */
    public readonly array $transitions;

    /**
     * @param list<string> $lanes
     * @param StatusToLaneMap $statusToLane Empty means "no restriction": any status is
     *                                      allowed in any lane. Non-empty entries restrict
     *                                      the named lane to the listed statuses.
     * @param WipLimitMap $wipLimits Keyed by a lane name, or a comma-joined list of
     *                               lane names to cap their combined total, e.g.
     *                               `"READY,DOING,VERIFY" => 3`.
     * @param RequiredFieldsMap|null $requiredFieldsByLane Field names refer to {@see \voku\AgentKanban\Domain\Card}
     *                                                     property names (e.g. `summary`, `taskBrief`, `assignee`).
     *                                                     Defaults to requiring a `taskBrief` in the `READY` lane,
     *                                                     but only if `READY` is actually one of `$lanes`.
     * @param TransitionMap|null $transitions Defaults to {@see self::DEFAULT_TRANSITIONS}, but only
     *                                        if `$lanes` is exactly the default lane set; otherwise
     *                                        defaults to no configured transitions (every move must
     *                                        be configured explicitly).
     */
    public function __construct(
        public string $projectPrefix,
        public string $cardDirectory = self::PREFERRED_CARD_DIRECTORY,
        public string $legacyCardDirectory = self::LEGACY_CARD_DIRECTORY,
        public ?string $archiveDirectory = null,
        public array $lanes = self::DEFAULT_LANES,
        public array $statusToLane = [],
        public array $wipLimits = [],
        ?array $requiredFieldsByLane = null,
        ?array $transitions = null,
        public int $formatVersion = self::CURRENT_FORMAT_VERSION,
        public ?string $externalIssueSystem = null,
    ) {
        $this->requiredFieldsByLane = $requiredFieldsByLane
            ?? (in_array('READY', $this->lanes, true) ? ['READY' => ['taskBrief']] : []);
        $this->transitions = $transitions
            ?? (array_diff(self::DEFAULT_LANES, $this->lanes) === [] ? self::DEFAULT_TRANSITIONS : []);

        $this->assertValidPrefix($projectPrefix);
        $this->assertValidLanes($lanes);
        $this->assertLaneReferencesAreKnown('statusToLane', array_keys($statusToLane));
        $this->assertLaneReferencesAreKnown('requiredFieldsByLane', array_keys($this->requiredFieldsByLane));
        $this->assertValidWipLimits($wipLimits);
        $this->assertValidTransitions($this->transitions);
    }

    public static function default(string $projectPrefix): self
    {
        return new self($projectPrefix);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['projectPrefix']) || !is_string($data['projectPrefix']) || $data['projectPrefix'] === '') {
            throw new ConfigurationException('Config requires a non-empty string "projectPrefix".');
        }

        /**
         * @var array{
         *     projectPrefix: string,
         *     cardDirectory?: string,
         *     legacyCardDirectory?: string,
         *     archiveDirectory?: string|null,
         *     lanes?: list<string>,
         *     statusToLane?: StatusToLaneMap,
         *     wipLimits?: WipLimitMap,
         *     requiredFieldsByLane?: RequiredFieldsMap,
         *     transitions?: TransitionMap,
         *     formatVersion?: int,
         *     externalIssueSystem?: string|null
         * } $data
         */
        return new self(
            projectPrefix: $data['projectPrefix'],
            cardDirectory: $data['cardDirectory'] ?? self::PREFERRED_CARD_DIRECTORY,
            legacyCardDirectory: $data['legacyCardDirectory'] ?? self::LEGACY_CARD_DIRECTORY,
            archiveDirectory: $data['archiveDirectory'] ?? null,
            lanes: $data['lanes'] ?? self::DEFAULT_LANES,
            statusToLane: $data['statusToLane'] ?? [],
            wipLimits: $data['wipLimits'] ?? [],
            requiredFieldsByLane: $data['requiredFieldsByLane'] ?? null,
            transitions: $data['transitions'] ?? null,
            formatVersion: $data['formatVersion'] ?? self::CURRENT_FORMAT_VERSION,
            externalIssueSystem: $data['externalIssueSystem'] ?? null,
        );
    }

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigurationException(sprintf('Config file not found: %s', $path), configPath: $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ConfigurationException(sprintf('Could not read config file: %s', $path), configPath: $path);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                sprintf('Invalid JSON in config file %s: %s', $path, $exception->getMessage()),
                configPath: $path,
            );
        }

        return self::fromArray($data);
    }

    public function supportsLane(Lane $lane): bool
    {
        return in_array($lane->toString(), $this->lanes, true);
    }

    /**
     * @return array{
     *     projectPrefix: string,
     *     cardDirectory: string,
     *     legacyCardDirectory: string,
     *     archiveDirectory: string|null,
     *     lanes: list<string>,
     *     statusToLane: StatusToLaneMap,
     *     wipLimits: WipLimitMap,
     *     requiredFieldsByLane: RequiredFieldsMap,
     *     transitions: TransitionMap,
     *     formatVersion: int,
     *     externalIssueSystem: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'projectPrefix'        => $this->projectPrefix,
            'cardDirectory'        => $this->cardDirectory,
            'legacyCardDirectory'  => $this->legacyCardDirectory,
            'archiveDirectory'     => $this->archiveDirectory,
            'lanes'                => $this->lanes,
            'statusToLane'         => $this->statusToLane,
            'wipLimits'            => $this->wipLimits,
            'requiredFieldsByLane' => $this->requiredFieldsByLane,
            'transitions'          => $this->transitions,
            'formatVersion'        => $this->formatVersion,
            'externalIssueSystem'  => $this->externalIssueSystem,
        ];
    }

    private function assertValidPrefix(string $prefix): void
    {
        if (preg_match('/^[A-Z][A-Z0-9]*$/', $prefix) !== 1) {
            throw new ConfigurationException(
                sprintf('Invalid project prefix "%s": expected an uppercase alphanumeric identifier.', $prefix),
            );
        }
    }

    /**
     * @param list<string> $lanes
     */
    private function assertValidLanes(array $lanes): void
    {
        if ($lanes === []) {
            throw new ConfigurationException('BoardConfig requires at least one lane.');
        }

        $seen = [];
        foreach ($lanes as $lane) {
            $normalized = Lane::fromString($lane)->toString();
            if (isset($seen[$normalized])) {
                throw new ConfigurationException(sprintf('Duplicate lane in configuration: %s', $normalized));
            }

            $seen[$normalized] = true;
        }
    }

    /**
     * @param list<string> $references
     */
    private function assertLaneReferencesAreKnown(string $configKey, array $references): void
    {
        foreach ($references as $reference) {
            if (!in_array($reference, $this->lanes, true)) {
                throw new ConfigurationException(
                    sprintf('Config key "%s" references unknown lane "%s".', $configKey, $reference),
                );
            }
        }
    }

    /**
     * @param WipLimitMap $wipLimits
     */
    private function assertValidWipLimits(array $wipLimits): void
    {
        foreach ($wipLimits as $group => $limit) {
            if ($limit < 0) {
                throw new ConfigurationException(sprintf('WIP limit for "%s" must not be negative.', $group));
            }

            foreach (explode(',', $group) as $lane) {
                if (!in_array(trim($lane), $this->lanes, true)) {
                    throw new ConfigurationException(
                        sprintf('WIP limit group "%s" references unknown lane "%s".', $group, trim($lane)),
                    );
                }
            }
        }
    }

    /**
     * @param TransitionMap $transitions
     */
    private function assertValidTransitions(array $transitions): void
    {
        foreach ($transitions as $from => $targets) {
            if (!in_array($from, $this->lanes, true)) {
                throw new ConfigurationException(sprintf('Transition source references unknown lane "%s".', $from));
            }

            foreach ($targets as $target) {
                if (!in_array($target, $this->lanes, true)) {
                    throw new ConfigurationException(
                        sprintf('Transition target "%s" (from %s) is not a known lane.', $target, $from),
                    );
                }
            }
        }
    }
}
