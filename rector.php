<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveParentDelegatingConstructorRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/examples'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true)
    ->withSkip([ReadOnlyClassRector::class, ReadOnlyPropertyRector::class, RemoveParentDelegatingConstructorRector::class, RemoveNonExistingVarAnnotationRector::class]);
