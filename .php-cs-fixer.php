<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in(__DIR__.'/src')
    ->notPath('DependencyInjection/Configuration.php')
    ->in(__DIR__.'/tests')
    ->exclude('App/var')
    ->append([__FILE__])
;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
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
        'ordered_traits' => true,
        'php_unit_size_class' => true,
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_types' => true,
        'phpdoc_to_param_type' => true,
        'phpdoc_to_property_type' => true,
        'phpdoc_to_return_type' => true,
        'regular_callable_call' => true,
        'self_static_accessor' => true,
        'simplified_if_return' => true,
        'simplified_null_return' => true,
        'static_lambda' => true,
        'get_class_to_class_keyword' => true,
    ])
    ->setFinder($finder)
;
