<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The studio's entity sets are read whole (every status, comment and render request of a canvas). A set with
 * QueryOptionsTrait gets the default `top` of the query options, so a canvas with more than 50 boards lost the rows
 * beyond the 50th. No route of the studio offers query options, so no set carries the trait.
 */
class DesignStudioEntitySetsTest extends TestCase
{
    public function testNoEntitySetIsCutByTheDefaultTopOfQueryOptions(): void
    {
        $entitiesDirectory = dirname(__DIR__, 2) . '/src/Domain/DesignStudio/Entities';
        $entitySetClassNames = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entitiesDirectory)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($entitiesDirectory) + 1, -4);
            $className = 'DDD\\Domain\\DesignStudio\\Entities\\' . str_replace('/', '\\', $relativePath);
            if (class_exists($className) && is_subclass_of($className, EntitySet::class)) {
                $entitySetClassNames[] = $className;
            }
        }

        $this->assertNotEmpty($entitySetClassNames);
        foreach ($entitySetClassNames as $entitySetClassName) {
            $this->assertFalse(
                ReflectionClass::instance($entitySetClassName)->hasTrait(QueryOptionsTrait::class),
                "$entitySetClassName uses QueryOptionsTrait: its reads would stop at the default top"
            );
        }
    }
}
