<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioCommentViewport;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioBoardView;
use DDD\Domain\DesignStudio\Services\DesignDevicesService;
use DDD\Domain\DesignStudio\Services\DesignStudioCanvasViewsService;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Responsive boards: the marker (canvas.json of the board or the canvas, or the meta in the file), the device frames
 * (board > canvas > configured default), the route of a page, one render target and thumbnail key per frame, and the
 * viewport of a comment.
 */
class DesignResponsiveBoardsTest extends TestCase
{
    protected string $projectRootDirectory;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-responsive-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    protected function createCanvasDefinition(): DesignCanvasDefinition
    {
        $canvasDefinition = new DesignCanvasDefinition();
        $canvasDefinition->projectKey = 'app';
        $canvasDefinition->canvasKey = 'site';
        $canvasDefinition->folder = 'design/canvas';
        $canvasDefinition->boardPatterns = [];
        $canvasDefinition->sectionPatterns = [];
        return $canvasDefinition;
    }

    /**
     * @param array $canvasDescription canvas.json
     * @param string[] $boardContents file name => HTML
     */
    protected function readBoards(array $canvasDescription, array $boardContents): DesignDirectoryBoards
    {
        $canvasFolder = $this->projectRootDirectory . '/design/canvas';
        foreach ($boardContents as $boardKey => $boardContent) {
            file_put_contents($canvasFolder . '/' . $boardKey, $boardContent);
        }
        file_put_contents($canvasFolder . '/canvas.json', json_encode($canvasDescription));
        $designDirectoryReaderService = new FixedRootDesignDirectoryReaderService($this->projectRootDirectory);
        return $designDirectoryReaderService->readCanvas($this->createCanvasDefinition())->boards;
    }

    public function testTheMarkerTheFramesAndTheRouteComeFromCanvasJsonOrTheFile(): void
    {
        $designDirectoryBoards = $this->readBoards(
            [
                'devices' => ['desktop-1440', 'iphone-16'],
                'boards' => [
                    'home.html' => ['x' => 0, 'y' => 0, 'responsive' => true, 'route' => '/'],
                    'pricing.html' => ['x' => 500, 'y' => 0, 'responsive' => true, 'devices' => ['iphone-se', 'unknown-device'], 'route' => 'pricing/'],
                    'meta.html' => ['x' => 1000, 'y' => 0],
                    'fixed.html' => ['x' => 1500, 'y' => 0, 'route' => 'https://example.com/x'],
                ],
            ],
            [
                'home.html' => '<p>home</p>',
                'pricing.html' => '<p>pricing</p>',
                'meta.html' => '<head><meta name="design-responsive"><meta content="/contact" name="design-route"></head><p>contact</p>',
                'fixed.html' => '<p>fixed</p>',
            ]
        );
        $boardsByKey = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            $boardsByKey[$designDirectoryBoard->boardKey] = $designDirectoryBoard;
        }

        $this->assertTrue($boardsByKey['home.html']->isResponsive);
        $this->assertSame(['desktop-1440', 'iphone-16'], $boardsByKey['home.html']->frameDeviceKeys, 'the canvas list');
        $this->assertSame('/', $boardsByKey['home.html']->route);
        $this->assertSame(['iphone-se'], $boardsByKey['pricing.html']->frameDeviceKeys, 'the board list, unknown keys left out');
        $this->assertSame('/pricing', $boardsByKey['pricing.html']->route);
        $this->assertTrue($boardsByKey['meta.html']->isResponsive, 'marked in the file');
        $this->assertSame(['desktop-1440', 'iphone-16'], $boardsByKey['meta.html']->frameDeviceKeys);
        $this->assertSame('/contact', $boardsByKey['meta.html']->route, 'route from the file');
        $this->assertFalse($boardsByKey['fixed.html']->isResponsive);
        $this->assertSame([], $boardsByKey['fixed.html']->frameDeviceKeys);
        $this->assertNull($boardsByKey['fixed.html']->route, 'an external URL is no route');
    }

    public function testWithoutAListTheConfiguredDefaultFramesApply(): void
    {
        $designDirectoryBoards = $this->readBoards(
            ['responsive' => true, 'boards' => ['home.html' => ['x' => 0, 'y' => 0]]],
            ['home.html' => '<p>home</p>']
        );
        $this->assertSame(
            (new DesignDevicesService())->getDefaultFrameDeviceKeys(),
            $designDirectoryBoards->first()->frameDeviceKeys
        );
    }

    public function testEachFrameIsARenderTargetWithItsOwnThumbnailKeyAndShard(): void
    {
        $designDirectoryBoards = $this->readBoards(
            ['boards' => [
                'home.html' => ['x' => 0, 'y' => 0, 'responsive' => true, 'devices' => ['desktop-1440', 'iphone-16']],
                'fixed.html' => ['x' => 500, 'y' => 0, 'w' => 390, 'h' => 844],
            ]],
            ['home.html' => '<p>home</p>', 'fixed.html' => '<p>fixed</p>']
        );
        $designStudioThumbnailsService = new class extends DesignStudioThumbnailsService {
            public function __construct()
            {
            }

            public function getRendererVersion(): string
            {
                return 'r1';
            }
        };

        $renderTargets = $designStudioThumbnailsService->expandRenderTargets($designDirectoryBoards);

        $targetKeys = [];
        $thumbnailKeys = [];
        foreach ($renderTargets->getElements() as $renderTarget) {
            $targetKeys[] = $renderTarget->getRenderTargetKey() . ' ' . $renderTarget->width . 'x' . $renderTarget->height . ' ' . var_export($renderTarget->getFrameWidth(), true);
            $thumbnailKeys[] = $designStudioThumbnailsService->computeThumbnailKeyForBoard($renderTarget, 'theme');
        }
        $this->assertSame(
            ['home.html@desktop-1440 1440x900 1440', 'home.html@iphone-16 393x852 393', 'fixed.html 390x844 NULL'],
            $targetKeys
        );
        $this->assertCount(3, array_unique($thumbnailKeys));
        $fixedBoard = $renderTargets->getElements()[2];
        $this->assertSame(
            $designStudioThumbnailsService->computeThumbnailKey((string)$fixedBoard->contentHash, 'theme', null),
            $thumbnailKeys[2],
            'a fixed board keeps the key it had before frames'
        );
        $this->assertSame(3, $designStudioThumbnailsService->expandRenderTargets($renderTargets)->count(), 'targets stay targets');
        $this->assertSame(
            DesignStudioThumbnailRenderRequestsService::getShardOfBoard('home.html@iphone-16', 4),
            DesignStudioThumbnailRenderRequestsService::getShardOfBoard($renderTargets->getElements()[1]->getRenderTargetKey(), 4)
        );
    }

    public function testMetaContentIsReadWithEitherAttributeOrderAndQuoting(): void
    {
        $this->assertSame('/a', DesignDirectoryReaderService::findMetaContent('<meta name="design-route" content="/a">', 'design-route'));
        $this->assertSame('/b', DesignDirectoryReaderService::findMetaContent("<META content='/b' NAME=design-route />", 'design-route'));
        $this->assertSame('', DesignDirectoryReaderService::findMetaContent('<meta name="design-responsive">', 'design-responsive'));
        $this->assertNull(DesignDirectoryReaderService::findMetaContent('<meta name="design-responsive-x">', 'design-responsive'));
        $this->assertSame('/', DesignDirectoryReaderService::normalizeRoute('/'));
        $this->assertSame('/a/b', DesignDirectoryReaderService::normalizeRoute('a/b/'));
        $this->assertNull(DesignDirectoryReaderService::normalizeRoute('/a?x=1'));
        $this->assertNull(DesignDirectoryReaderService::normalizeRoute('/../x'));
    }

    public function testACommentViewportNeedsAUsableWidth(): void
    {
        $designStudioCommentViewport = DesignStudioCommentViewport::fromWidthHeightAndDeviceKey(393, 852, 'iphone-16');
        $this->assertSame('393x852 iphone-16', $designStudioCommentViewport->toReferenceText());
        $this->assertSame('800x800', DesignStudioCommentViewport::fromWidthHeightAndDeviceKey(800, null, ' ')->toReferenceText());
        $this->assertNull(DesignStudioCommentViewport::fromWidthHeightAndDeviceKey(null, 852, 'iphone-16'));
        $this->assertNull(DesignStudioCommentViewport::fromWidthHeightAndDeviceKey(50, 852, null));
    }

    public function testTheCanvasViewShowsOneFramePerDeviceSideBySide(): void
    {
        $designDirectoryBoards = $this->readBoards(
            ['boards' => ['home.html' => ['x' => 0, 'y' => 0, 'responsive' => true, 'devices' => ['desktop-1440', 'iphone-16']]]],
            ['home.html' => '<p>home</p>']
        );
        $designDirectoryBoard = $designDirectoryBoards->first();
        $designStudioThumbnailsService = new class extends DesignStudioThumbnailsService {
            public function __construct()
            {
            }

            public function getRendererVersion(): string
            {
                return 'r1';
            }
        };
        (new \ReflectionProperty(\DDD\DDDBundle::class, 'defaultContainer'))->getValue()
            ->setTestInstance(DesignStudioThumbnailsService::class, $designStudioThumbnailsService);
        $phoneThumbnail = new DesignStudioThumbnail();
        $phoneThumbnail->id = 1;
        $phoneThumbnail->thumbnailKey = $designStudioThumbnailsService->computeThumbnailKey((string)$designDirectoryBoard->contentHash, 'theme', null, 393);
        $phoneThumbnail->height = 2400;
        $designStudioThumbnails = new DesignStudioThumbnails();
        $designStudioThumbnails->add($phoneThumbnail);
        $designStudioCanvasViewsService = new class extends DesignStudioCanvasViewsService {
            public function addFrameViewsForTest(...$arguments): void
            {
                $this->addFrameViews(...$arguments);
            }
        };
        $designStudioBoardView = new DesignStudioBoardView();

        $designStudioCanvasViewsService->addFrameViewsForTest(
            $designStudioBoardView,
            $designDirectoryBoard,
            (new DesignDevicesService())->getDevices(),
            $designStudioThumbnails,
            'theme',
            '/content/thumbnails/',
            'token=t'
        );
        (new \ReflectionProperty(\DDD\DDDBundle::class, 'defaultContainer'))->getValue()
            ->setTestInstance(DesignStudioThumbnailsService::class, null);

        $this->assertTrue($designStudioBoardView->isResponsive);
        $frames = $designStudioBoardView->frames->getElements();
        $this->assertSame(['desktop-1440', 'iphone-16'], array_map(static fn($frame) => $frame->deviceKey, $frames));
        $this->assertSame(1440 + DesignStudioCanvasViewsService::FRAME_GAP + 393, $designStudioBoardView->width);
        $this->assertSame(900, $designStudioBoardView->height);
        $this->assertNull($frames[0]->thumbnailUrl, 'not rendered yet');
        $this->assertSame('/content/thumbnails/' . $phoneThumbnail->thumbnailKey . '?token=t', $frames[1]->thumbnailUrl);
        $this->assertSame(2400, $frames[1]->pageHeight);
    }

    public function testARouteNamesOnePage(): void
    {
        $canvasFolder = $this->projectRootDirectory . '/design/canvas';
        file_put_contents($canvasFolder . '/a.html', '<p>a</p>');
        file_put_contents($canvasFolder . '/b.html', '<p>b</p>');
        file_put_contents($canvasFolder . '/canvas.json', json_encode(['boards' => [
            'a.html' => ['x' => 0, 'y' => 0, 'route' => '/pricing'],
            'b.html' => ['x' => 0, 'y' => 500, 'route' => '/pricing/'],
        ]]));
        $designDirectoryCanvas = (new FixedRootDesignDirectoryReaderService($this->projectRootDirectory))->readCanvas($this->createCanvasDefinition());
        $routes = array_map(static fn($board) => $board->route, $designDirectoryCanvas->boards->getElements());
        $this->assertSame(['/pricing', null], $routes);
        $this->assertContains('Route /pricing of board b.html is taken by a.html', $designDirectoryCanvas->warnings);
    }
}
