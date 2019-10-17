<?php

$config = PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP73Migration' => true,
        '@PHP70Migration:risky' => true,
        '@DoctrineAnnotation' => true,
        '@PHPUnit60Migration:risky' => true,
        'backtick_to_shell_exec' => true,
        'date_time_immutable' => false,
        'declare_strict_types' => false,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedException',
                'expectedExceptionMessage',
                'expectedExceptionMessageRegExp',
            ],
        ],
        'linebreak_after_opening_tag' => true,
        'list_syntax' => ['syntax' => 'long'],
        'mb_str_functions' => true,
        'no_superfluous_phpdoc_tags' => false,
        'ordered_class_elements' => false,
        'ordered_interfaces' => true,
        'simplified_null_return' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->notPath([
                'src/DoctrineAuditBundle/DependencyInjection/Configuration.php',
                'tests/DoctrineAuditBundle/Manager/AuditManagerTest.php',
                'tests/DoctrineAuditBundle/Event/DoctrineSubscriberTest.php',
            ])
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
