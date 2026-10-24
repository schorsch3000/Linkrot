<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app/src', __DIR__ . '/htdocs', __DIR__ . '/tests'])
    ->append([__DIR__ . '/app/bin/lr-auth', __FILE__, __DIR__ . '/rector.php', __DIR__ . '/scripts/preview.php']);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
