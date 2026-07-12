<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__DIR__ . '/bin/agent-kanban']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                      => true,
        '@PSR12:risky'                => true,
        '@PHP83Migration'             => true,
        'strict_param'                => true,
        'declare_strict_types'        => true,
        'array_syntax'                => ['syntax' => 'short'],
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'           => true,
        'single_quote'                => true,
        'trailing_comma_in_multiline' => true,
        'no_superfluous_phpdoc_tags'  => ['allow_mixed' => true],
        'phpdoc_align'                => ['align' => 'left'],
        'phpdoc_separation'           => true,
        'blank_line_after_opening_tag' => true,
        'no_empty_statement'          => true,
        'no_extra_blank_lines'        => true,
        'method_argument_space'       => ['on_multiline' => 'ensure_fully_multiline'],
    ])
    ->setFinder($finder);
