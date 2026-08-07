<?php
/**
 * php-cs-fixer configuration — PSR-12 with safe extras.
 *
 * CI runs it in dry-run mode on the PHP files changed in a PR/push only
 * (see .github/workflows/ci.yml), so legacy files are never reformatted
 * wholesale while new code is held to the style.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', '.freebuff', '.git', '.phpstan-cache']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    ])
    ->setFinder($finder);
