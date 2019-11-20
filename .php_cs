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
        'backtick_to_shell_exec' => true,
        'date_time_immutable' => false,
        'declare_strict_types' => false,
        'final_public_method_for_abstract_class' => true,
        'final_static_access' => true,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedException',
                'expectedExceptionMessage',
                'expectedExceptionMessageRegExp',
            ],
        ],
        'global_namespace_import' => true,
        'linebreak_after_opening_tag' => true,
        'mb_str_functions' => true,
        'no_superfluous_phpdoc_tags' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_interfaces' => true,
        'phpdoc_line_span' => true,
        'simplified_null_return' => false,
        'php_unit_test_class_requires_covers' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->notPath('src/DoctrineAuditBundle/DependencyInjection/Configuration.php')
            ->notPath('tests/DoctrineAuditBundle/Manager/AuditManagerTest.php')
            ->notPath('tests/DoctrineAuditBundle/Event/DoctrineSubscriberTest.php')
            ->in(__DIR__)
    )
;

// special handling of fabbot.io service if it's using too old PHP CS Fixer version
try {
    PhpCsFixer\FixerFactory::create()
        ->registerBuiltInFixers()
        ->registerCustomFixers($config->getCustomFixers())
        ->useRuleSet(new PhpCsFixer\RuleSet($config->getRules()));
} catch (PhpCsFixer\ConfigurationException\InvalidConfigurationException $e) {
    $config->setRules([]);
} catch (UnexpectedValueException $e) {
    $config->setRules([]);
} catch (InvalidArgumentException $e) {
    $config->setRules([]);
}

return $config;
