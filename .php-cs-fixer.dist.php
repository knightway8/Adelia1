<?php
declare(strict_types=1);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x5Migration' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'modernize_types_casting' => true,
        'no_alias_functions' => true,
        'no_extra_blank_lines' => true,
        'single_quote' => true,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder(PhpCsFixer\Finder::create()->in(__DIR__));
