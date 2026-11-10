<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Services\DesignProjectDefinitionsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

/**
 * DesignProjectDefinitionsService turns the config `DesignStudio.projects` into definitions and refuses unsafe ones.
 */
class DesignProjectDefinitionsServiceTest extends TestCase
{
    protected function createService(?array $projectsConfig = null): DesignProjectDefinitionsService
    {
        return new class($projectsConfig) extends DesignProjectDefinitionsService {
            public function __construct(protected ?array $testProjectsConfig)
            {
            }

            protected function getProjectsConfig(): array
            {
                return $this->testProjectsConfig ?? parent::getProjectsConfig();
            }
        };
    }

    public function testReadsTheProjectsConfig(): void
    {
        $service = $this->createService([[
            'key' => 'app',
            'label' => 'App',
            'path' => 'app',
            'canvases' => [[
                'key' => 'onboarding',
                'label' => 'Onboarding',
                'folder' => 'design/onboarding',
                'boards' => ['ob-*'],
                'sections' => ['s-*'],
                'referenceTheme' => null,
            ]],
        ]]);
        $canvasDefinition = $service->getCanvasDefinition('app', 'onboarding');
        $this->assertNotNull($canvasDefinition);
        $this->assertSame('app', $canvasDefinition->projectKey);
        $this->assertSame('design/onboarding', $canvasDefinition->folder);
        $this->assertSame(['ob-*'], $canvasDefinition->boardPatterns);
        $this->assertSame(['s-*'], $canvasDefinition->sectionPatterns);
        $this->assertNull($service->getCanvasDefinition('app', 'unknown'));
        $this->assertNull($service->getCanvasDefinition('unknown', 'onboarding'));
    }

    public function testTheModuleDefaultHasNoProjects(): void
    {
        $this->assertTrue($this->createService()->getProjectDefinitions()->isEmpty());
    }

    /**
     * @dataProvider provideInvalidConfigs
     */
    public function testRefusesInvalidConfig(array $projectsConfig): void
    {
        $this->expectException(BadRequestException::class);
        $this->createService($projectsConfig)->getProjectDefinitions();
    }

    public static function provideInvalidConfigs(): array
    {
        $canvas = fn(string $key, string $folder) => ['key' => $key, 'folder' => $folder];
        return [
            'project key with slash' => [[['key' => 'a/b']]],
            'duplicate project key' => [[['key' => 'app'], ['key' => 'app']]],
            'duplicate canvas key' => [[['key' => 'app', 'canvases' => [$canvas('one', 'design/a'), $canvas('one', 'design/b')]]]],
            'folder with ..' => [[['key' => 'app', 'canvases' => [$canvas('one', 'design/../../etc')]]]],
            'absolute folder' => [[['key' => 'app', 'canvases' => [$canvas('one', '/etc')]]]],
            'no folder' => [[['key' => 'app', 'canvases' => [['key' => 'one']]]]],
        ];
    }
}
