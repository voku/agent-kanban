<?php

declare(strict_types=1);

namespace voku\AgentKanban\Tests\Support;

/**
 * Deliberately does NOT implement ExternalIssueProvider. Its constructor
 * writes a marker file so a test can prove the CLI never instantiates a
 * `--provider-class` before verifying it implements the interface (see
 * CliApplication::cmdExternalSync()).
 */
final class NotAnExternalIssueProviderWithSideEffect
{
    public function __construct()
    {
        $marker = getenv('AGENT_KANBAN_TEST_SIDE_EFFECT_MARKER');
        if ($marker !== false) {
            file_put_contents($marker, 'constructed');
        }
    }
}
