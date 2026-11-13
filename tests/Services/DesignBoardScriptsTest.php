<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Services\DesignBoardAssetsService;
use DDD\Domain\DesignStudio\Services\DesignBoardContentService;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignProjectDefinitionsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Presentation\Api\Admin\DesignStudio\Traits\DesignStudioContentControllerTrait;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A canvas with board scripts (`'scripts' => true`): its boards keep their `<script>` elements, the CSP admits inline
 * scripts and the canvas' script files only (no network, opaque origin), thumbnails show the state after the scripts
 * ran and have keys of their own. Every other canvas stays without scripts.
 */
class DesignBoardScriptsTest extends TestCase
{
    protected const string BOARD_HTML = '<html><head></head><body><p id="p">x</p>'
        . '<script>setInterval(function(){document.getElementById("p").classList.toggle("on")},1000)</script>'
        . '<script src="assets/tour.js"></script></body></html>';

    protected string $projectRootDirectory;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-scripts-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas/assets', 0777, true);
        file_put_contents($this->projectRootDirectory . '/design/canvas/canvas.json', '{"boards":{"home.html":{}}}');
        file_put_contents($this->projectRootDirectory . '/design/canvas/home.html', static::BOARD_HTML);
        file_put_contents($this->projectRootDirectory . '/design/canvas/assets/tour.js', 'window.tour=1;');
        $this->getTestContainer()->setTestInstance(
            DesignDirectoryReaderService::class,
            new FixedRootDesignDirectoryReaderService($this->projectRootDirectory, 1000)
        );
    }

    protected function tearDown(): void
    {
        $this->getTestContainer()->setTestInstance(DesignDirectoryReaderService::class, null);
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }

    protected function createCanvasDefinition(bool $runsBoardScripts): DesignCanvasDefinition
    {
        $canvasDefinition = new DesignCanvasDefinition();
        $canvasDefinition->projectKey = 'app';
        $canvasDefinition->canvasKey = 'test';
        $canvasDefinition->folder = 'design/canvas';
        $canvasDefinition->runsBoardScripts = $runsBoardScripts;
        return $canvasDefinition;
    }

    /**
     * @return object the content controller trait with its CSP method callable
     */
    protected function createContentController(): object
    {
        return new class {
            use DesignStudioContentControllerTrait;

            /**
             * @return string[]
             */
            public function buildDirectives(?string $applicationOrigin, bool $isSiteView, ?string $boardScriptFilesSource, bool $runsBoardScripts): array
            {
                return $this->getBoardHtmlContentSecurityPolicyDirectives($applicationOrigin, $isSiteView, $boardScriptFilesSource, $runsBoardScripts);
            }
        };
    }

    public function testTheCanvasOptsInWithScriptsAndIsOffByDefault(): void
    {
        $designProjectDefinitionsService = new class extends DesignProjectDefinitionsService {
            protected function getProjectsConfig(): array
            {
                return [[
                    'key' => 'site',
                    'path' => 'site',
                    'canvases' => [
                        ['key' => 'draft', 'folder' => 'design/draft', 'scripts' => true],
                        ['key' => 'plain', 'folder' => 'design/plain'],
                    ],
                ]];
            }
        };

        $this->assertTrue($designProjectDefinitionsService->getCanvasDefinition('site', 'draft')->runsBoardScripts);
        $this->assertFalse($designProjectDefinitionsService->getCanvasDefinition('site', 'plain')->runsBoardScripts);
    }

    public function testOnlyACanvasWithBoardScriptsKeepsThem(): void
    {
        $designBoardContentService = new DesignBoardContentService();

        $withScripts = $designBoardContentService->getBoardHtmlForSandbox($this->createCanvasDefinition(true), 'home.html', null, null, false);
        $withoutScripts = $designBoardContentService->getBoardHtmlForSandbox($this->createCanvasDefinition(false), 'home.html', null, null, false);

        $this->assertSame(static::BOARD_HTML, $withScripts);
        $this->assertStringNotContainsString('<script', (string)$withoutScripts);
        $this->assertStringContainsString('<p id="p">x</p>', (string)$withoutScripts);
    }

    public function testTheBoardsCarryTheFlagOfTheirCanvas(): void
    {
        $designDirectoryReaderService = DDDBundle::getContainer()->get(DesignDirectoryReaderService::class);

        $withScripts = $designDirectoryReaderService->readCanvas($this->createCanvasDefinition(true))->boards->first();
        $withoutScripts = $designDirectoryReaderService->readCanvas($this->createCanvasDefinition(false))->boards->first();

        $this->assertTrue($withScripts->runsScripts);
        $this->assertFalse($withoutScripts->runsScripts);
        // the script file is an asset of the board: a new version of it reloads the board and renders it anew
        $this->assertNotNull($withScripts->assetsHash);
    }

    public function testTheCspAdmitsInlineScriptsAndTheCanvasScriptFilesOnly(): void
    {
        $scriptFilesSource = 'https://admin.example.com/api/admin/designStudio/content/assets/app/test/';
        $directives = $this->createContentController()->buildDirectives('https://admin.example.com', false, $scriptFilesSource, true);

        $this->assertContains("script-src 'unsafe-inline' " . $scriptFilesSource, $directives);
        $this->assertContains('sandbox allow-scripts', $directives);
        $this->assertContains("connect-src 'none'", $directives);
        $this->assertContains("form-action 'none'", $directives);
        $this->assertContains("default-src 'none'", $directives);
        $this->assertNotContains('sandbox allow-scripts allow-same-origin', $directives);
    }

    public function testTheSiteViewOfACanvasWithBoardScriptsRunsTheNavigationScriptAsAnInlineScript(): void
    {
        $directives = $this->createContentController()->buildDirectives('https://admin.example.com', true, null, true);

        // a hash next to 'unsafe-inline' would switch 'unsafe-inline' off and block the board's own scripts
        $this->assertContains("script-src 'unsafe-inline'", $directives);
        $this->assertStringNotContainsString(DesignBoardContentService::getSiteNavigationScriptSource(), implode('; ', $directives));
        $this->assertContains('sandbox allow-scripts', $directives);
    }

    public function testACanvasWithoutBoardScriptsKeepsItsCsp(): void
    {
        $controller = $this->createContentController();

        $this->assertContains("script-src 'none'", $controller->buildDirectives('https://admin.example.com', false, null, false));
        $this->assertContains('sandbox', $controller->buildDirectives('https://admin.example.com', false, null, false));
        $this->assertContains(
            'script-src ' . DesignBoardContentService::getSiteNavigationScriptSource(),
            $controller->buildDirectives('https://admin.example.com', true, null, false)
        );
    }

    public function testScriptFilesAreAssetsAndTravelInlineToTheRenderer(): void
    {
        $this->assertTrue(DesignBoardAssetsService::isValidAssetPath('tour.js'));
        $this->assertSame('text/javascript', DesignBoardAssetsService::getMediaTypeForAssetPath('js/tour.js'));

        $inlinedHtml = (new DesignBoardAssetsService())->inlineAssetReferences('<script src="assets/tour.js"></script>', $this->createCanvasDefinition(true));

        $this->assertSame('<script src="data:text/javascript;base64,' . base64_encode('window.tour=1;') . '"></script>', $inlinedHtml);
    }

    public function testAThumbnailAfterTheScriptsHasAKeyOfItsOwn(): void
    {
        $designStudioThumbnailsService = new DesignStudioThumbnailsService();
        $themeHash = DesignThemeProviderService::computeThemeHash(null);

        $keyWithoutScripts = $designStudioThumbnailsService->computeThumbnailKey(sha1('a'), $themeHash, null, 393);
        $keyWithScripts = $designStudioThumbnailsService->computeThumbnailKey(sha1('a'), $themeHash, null, 393, true);

        $this->assertNotSame($keyWithoutScripts, $keyWithScripts);
        $designDirectoryBoard = new DesignDirectoryBoard();
        $designDirectoryBoard->contentHash = sha1('a');
        $designDirectoryBoard->runsScripts = true;
        $this->assertSame(
            $designStudioThumbnailsService->computeThumbnailKey(sha1('a'), $themeHash, null, null, true),
            $designStudioThumbnailsService->computeThumbnailKeyForBoard($designDirectoryBoard, $themeHash)
        );
    }

    public function testTheRendererIsAskedToRunScriptsOnlyWhenTheBoardRunsThem(): void
    {
        $renderer = new class extends ArgusDesignBoardRenderedThumbnails {
            public function buildPayload(): ?array
            {
                return $this->getLoadPayload();
            }
        };
        $renderer->html = '<p></p>';

        $this->assertArrayNotHasKey('scripts', $renderer->buildPayload()['body']);
        $renderer->runsScripts = true;
        $this->assertTrue($renderer->buildPayload()['body']['scripts']);
    }
}
