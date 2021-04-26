<?php

$config = PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP73Migration' => true,
        '@PHP71Migration:risky' => true,
        '@DoctrineAnnotation' => true,
        '@PHPUnit75Migration:risky' => true,
        'blank_line_before_statement' => [
            'statements' => [
                'break',
                // 'case', -> On ne souhaite pas ce cas
                'continue',
                'declare',
                // 'default',  -> On ne souhaite pas ce cas
                'exit',
                'goto',
                'include',
                'include_once',
                'require',
                'require_once',
                'return',
                'switch',
                'throw',
                'try',
            ],
        ],
        'date_time_immutable' => false,
        'declare_strict_types' => false,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedException',
                'expectedExceptionMessage',
                'expectedExceptionMessageRegExp',
            ],
        ],
        'global_namespace_import' => true,
        'list_syntax' => ['syntax' => 'short'],
        'mb_str_functions' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_interfaces' => true,
        'phpdoc_line_span' => true,
        // 'phpdoc_to_param_type' => true,
        // 'phpdoc_to_return_type' => true,
        // 'regular_callable_call' => true,
        'self_static_accessor' => true,
        // 'simplified_if_return' => true, // Fait bugger le cs-fixer (local principalement) en version < 3
        // 'simplified_null_return' => true,
        'php_unit_test_class_requires_covers' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->in(__DIR__)
        ->notPath('tests/App/var/')
        ->notPath('tests/App/cache/')
        ->notPath('src/DependencyInjection/Configuration.php')
    )
;

return $config;
