<?php
declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect()) // @TODO 4.0 no need to call this manually :poop:
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x2Migration' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@DoctrineAnnotation' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHPUnit10x0Migration:risky' => true,
//        'date_time_immutable' => true,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedDeprecation',
                'expectedException',
                'expectedExceptionMessage',
                'expectedExceptionMessageRegExp',
            ],
        ],
        'ordered_interfaces' => true,
        'ordered_traits' => true,
        'phpdoc_to_param_type' => true,
        'phpdoc_to_property_type' => true,
        'phpdoc_to_return_type' => true,
        'phpdoc_to_comment' => [
            'ignored_tags' => ['todo', 'var']
        ],
        'regular_callable_call' => true,
        'simplified_if_return' => true,
        'get_class_to_class_keyword' => true,
        'mb_str_functions' => true,
        'modernize_strpos' => true,
        'no_useless_concat_operator' => false, // TODO switch back on when the `src/Console/Application.php` no longer needs the concat
        'numeric_literal_separator' => true,
        'string_implicit_backslashes' => true, // https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/pull/7786
        'php_unit_test_case_static_method_calls' => false,
        'php_unit_test_class_requires_covers' => false,
    ])
    ->setFinder(
        (new Finder())
            ->ignoreDotFiles(false)
            ->ignoreVCSIgnored(true)
            ->in(__DIR__.'/src')
            ->notPath('DependencyInjection/Configuration.php')
            ->in(__DIR__.'/tests')
            ->exclude('App/var')
    )
;
