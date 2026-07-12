<?php

declare(strict_types=1);

namespace voku\AgentKanban\Legacy;

use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Repository\BoardMetadata;
use voku\AgentKanban\Repository\MarkdownCardRepository;

/**
 * Internal support for the deprecated 0.x facade classes
 * (`TodoBoardSource`, `TodoBoardVerifier`, `TodoBoardCli`). Not part of the
 * public API — use the typed engine (`BoardConfig`, `MarkdownCardRepository`,
 * ...) directly instead. See `UPGRADING.md`.
 *
 * @internal
 */
final readonly class LegacyBoardContext
{
    public function __construct(
        public BoardConfig $config,
        public MarkdownCardRepository $repository,
        public BoardMetadata $metadata,
        public string $cardDirectory,
    ) {
    }
}
