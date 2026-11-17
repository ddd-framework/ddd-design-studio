<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Commands;

use DDD\DDDBundle;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Symfony\Commands\DesignStudio\DesignStudioThumbnails as DesignStudioThumbnailsCommand;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:design-studio:thumbnails` asks the theme provider twice and warns when the answers differ: such a theme gets a
 * new look on every call, so no thumbnail ever matches it.
 */
class DesignStudioThumbnailsCommandTest extends TestCase
{
    protected string $projectRootDirectory;

    protected DesignCanvasDefinition $canvasDefinition;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-thumbnails-command-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas', 0777, true);
        file_put_contents($this->projectRootDirectory . '/design/canvas/home.html', '<p>home</p>');
        file_put_contents($this->projectRootDirectory . '/design/canvas/canvas.json', json_encode(['boards' => ['home.html' => ['x' => 0, 'y' => 0, 'w' => 390, 'h' => 844]]]));
        $this->canvasDefinition = new DesignCanvasDefinition();
        $this->canvasDefinition->projectKey = 'app';
        $this->canvasDefinition->canvasKey = 'onboarding';
        $this->canvasDefinition->folder = 'design/canvas';
        $this->canvasDefinition->boardPatterns = [];
        $this->canvasDefinition->sectionPatterns = [];
        $this->canvasDefinition->referenceTheme = 'brand:1';
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, new FixedRootDesignDirectoryReaderService($this->projectRootDirectory));
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, new class extends DesignStudioThumbnailsService {
            public function isRendererEnabled(): bool
            {
                return true;
            }

            public function findForBoards(DesignDirectoryBoards $designDirectoryBoards, string $themeHash): DesignStudioThumbnails
            {
                return new DesignStudioThumbnails();
            }
        });
        $testContainer->setTestInstance(DesignStudioThumbnailRenderRequestsService::class, new class extends DesignStudioThumbnailRenderRequestsService {
            public function findLatestRequest(DesignCanvasDefinition $canvasDefinition, string $themeHash): ?DesignStudioThumbnailRenderRequest
            {
                return null;
            }
        });
    }

    protected function tearDown(): void
    {
        $testContainer = $this->getTestContainer();
        foreach ([DesignDirectoryReaderService::class, DesignStudioThumbnailsService::class, DesignStudioThumbnailRenderRequestsService::class, DesignThemeProviderService::class] as $serviceClass) {
            $testContainer->setTestInstance($serviceClass, null);
        }
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }

    protected function runCommand(): string
    {
        $command = new class($this->canvasDefinition) extends DesignStudioThumbnailsCommand {
            public function __construct(protected DesignCanvasDefinition $testCanvasDefinition)
            {
                parent::__construct();
            }

            protected function authenticate(): void
            {
            }

            protected function getCanvasDefinition(InputInterface $input): DesignCanvasDefinition
            {
                return $this->testCanvasDefinition;
            }
        };
        $commandTester = new CommandTester($command);
        $commandTester->execute(['project' => 'app', 'canvas' => 'onboarding']);
        return $commandTester->getDisplay();
    }

    public function testAThemeThatReturnsADifferentStyleOnEachCallIsReported(): void
    {
        $this->getTestContainer()->setTestInstance(DesignThemeProviderService::class, new class extends DesignThemeProviderService {
            protected int $callCount = 0;

            public function getThemeStyle(string $themeId, string $mode): ?string
            {
                return '<style data-design-theme>:root { --encoded-at: "' . (++$this->callCount) . '" }</style>';
            }
        });

        $display = $this->runCommand();

        $this->assertStringContainsString('Warning: the theme returns different styles on each call (theme brand:1)', $display);
        $this->assertStringContainsString('0 of 1 pictures have a thumbnail', $display);
    }

    public function testAStableThemeIsNotReported(): void
    {
        $this->getTestContainer()->setTestInstance(DesignThemeProviderService::class, new class extends DesignThemeProviderService {
            public function getThemeStyle(string $themeId, string $mode): ?string
            {
                return '<style data-design-theme>:root { --accent: #0a7 }</style>';
            }
        });

        $display = $this->runCommand();

        $this->assertStringNotContainsString('Warning', $display);
        $this->assertStringContainsString('0 of 1 pictures have a thumbnail', $display);
    }
}
