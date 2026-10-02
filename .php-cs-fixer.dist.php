<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/config', __DIR__ . '/database'])
    ->append([__FILE__]);

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'fully_qualified_strict_types' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_order' => true,
        'phpdoc_align' => ['align' => 'left'],
    ])
    ->setFinder($finder);
