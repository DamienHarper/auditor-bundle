<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([
        __DIR__.'/tests/App/var/*',
        // Messenger resolves the handled message from the first __invoke() parameter.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/src/Retention/PruneAuditLogsHandler.php'],
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
    )
    ->withComposerBased(
        symfony: true,
        doctrine: true,
        phpunit: true,
    )
;
