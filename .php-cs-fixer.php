<?php

declare(strict_types=1);

$config = new PhpCsFixer\Config();
$config
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP74Migration:risky' => true,
        '@PHP80Migration' => true,
        '@PHP80Migration:risky' => true,
        '@DoctrineAnnotation' => true,
        '@PHPUnit84Migration:risky' => true,
        'date_time_immutable' => true,
        'final_public_method_for_abstract_class' => false,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedException',
                'expectedExceptionMessage',
                'expectedExceptionMessageRegExp',
            ],
        ],
        'global_namespace_import' => true,
        'linebreak_after_opening_tag' => true,
        'list_syntax' => ['syntax' => 'short'],
        'mb_str_functions' => true,
        'method_chaining_indentation' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_interfaces' => true,
        'ordered_traits' => false,
        'php_unit_size_class' => true,
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_types' => false,
        'phpdoc_to_param_type' => false,
        'phpdoc_to_property_type' => false,
        'phpdoc_to_return_type' => true,
        'regular_callable_call' => true,
        'self_static_accessor' => true,
        'simplified_if_return' => true,
        'simplified_null_return' => false,
        'static_lambda' => true,
        'get_class_to_class_keyword' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->in(__DIR__)
        ->notPath('src/DependencyInjection/Configuration.php')
        ->exclude('vendor')
        ->exclude('node_modules')
        ->exclude('tools')
        ->exclude('tests/App/var')
    )
;

return $config;
