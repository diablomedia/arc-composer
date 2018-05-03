<?php
$finder = PhpCsFixer\Finder::create()
    ->files()
    ->in(__DIR__ . DIRECTORY_SEPARATOR . 'lint')
;
return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR2' => true,
        'binary_operator_spaces' => ['align_double_arrow' => true, 'align_equals' => true],
        'single_quote' => false,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'dir_constant' => true,
        'psr0' => true,
    ])
    ->setUsingCache(true)
    ->setFinder($finder);
;

