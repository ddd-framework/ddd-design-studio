<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Support;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Container;

/** The suite's minimal container: services are resolved by instantiating the requested class */
class TestContainer extends Container
{
    protected array $testInstances = [];

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if (!isset($this->testInstances[$id])) {
            if (!class_exists($id)) {
                return null;
            }
            $reflectionClass = new ReflectionClass($id);
            $this->testInstances[$id] = $reflectionClass->getConstructor()
            && $reflectionClass->getConstructor()->getNumberOfRequiredParameters() > 0
                ? $reflectionClass->newInstanceWithoutConstructor()
                : new $id();
        }
        return $this->testInstances[$id];
    }

    /**
     * Replaces the instance the container answers for an id (a test double); null removes it.
     * @param string $id
     * @param object|null $service
     * @return void
     */
    public function setTestInstance(string $id, ?object $service): void
    {
        if ($service === null) {
            unset($this->testInstances[$id]);
            return;
        }
        $this->testInstances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return class_exists($id) || parent::has($id);
    }
}
