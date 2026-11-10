<?php

declare(strict_types=1);

/**
 * Framework objects assume a booted kernel (entities and services reach for the container). The suite installs a
 * minimal container and registers this module's config directory, so Config::get('DesignStudio.…') answers with the
 * module defaults. No application kernel, no database.
 */

use DDD\Infrastructure\Libs\Config;
use DDD\Tests\DesignStudio\Support\TestContainer;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

$moduleRoot = dirname(__DIR__);
require $moduleRoot . '/vendor/autoload.php';

if (!defined('APP_ROOT_DIR')) {
    define('APP_ROOT_DIR', $moduleRoot);
}

$testCacheDir = sys_get_temp_dir() . '/ddd_design_studio_phpunit_cache';
@mkdir($testCacheDir, 0777, true);
@touch($testCacheDir . '/service_class_map.php');
(new ReflectionProperty(DDD\DDDBundle::class, 'defaultContainer'))->setValue(null, new TestContainer(new ParameterBag([
    'kernel.cache_dir' => $testCacheDir,
    'kernel.project_dir' => $moduleRoot,
    'kernel.environment' => 'test',
])));

Config::addConfigDirectory($moduleRoot . '/config/app', isModule: true);
