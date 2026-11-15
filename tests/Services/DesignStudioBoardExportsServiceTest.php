<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardExportRegion;
use DDD\Domain\DesignStudio\Repo\Argus\Exports\ArgusDesignBoardVectorExport;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioBoardExportsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A board is exported as SVG through the renderer's `vector` job: the board found by file name or code, its asset files
 * inlined, the theme's style and the region sent, a responsive board in one device frame; the renderer's answer is read
 * into the export, a failure never throws.
 */
class DesignStudioBoardExportsServiceTest extends TestCase
{
    /** @var string A PNG of one pixel */
    public const string ONE_PIXEL_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x04\x00\x00\x00\xb5\x1c\x0c\x02\x00\x00\x00\x0bIDATx\xdacd`\x00\x00\x00\x06\x00\x020\x81\xd0/\x00\x00\x00\x00IEND\xaeB`\x82";

    protected string $projectRootDirectory;

    protected DesignCanvasDefinition $canvasDefinition;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-exports-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas/assets', 0777, true);
        file_put_contents($this->projectRootDirectory . '/design/canvas/assets/dot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        file_put_contents($this->projectRootDirectory . '/design/canvas/onb-w-2-welcome.dc.html', '<!doctype html><html><head></head><body><img src="assets/dot.png"></body></html>');
        file_put_contents($this->projectRootDirectory . '/design/canvas/home.html', '<!doctype html><html><head></head><body>Home</body></html>');
        file_put_contents($this->projectRootDirectory . '/design/canvas/canvas.json', json_encode(['boards' => [
            'onb-w-2-welcome.dc.html' => ['x' => 0, 'y' => 0, 'w' => 390, 'h' => 844],
            'home.html' => ['x' => 500, 'y' => 0, 'responsive' => true, 'devices' => ['iphone-16', 'desktop-1440']],
        ]]));
        $this->canvasDefinition = new DesignCanvasDefinition();
        $this->canvasDefinition->projectKey = 'app';
        $this->canvasDefinition->canvasKey = 'onboarding';
        $this->canvasDefinition->folder = 'design/canvas';
        $this->canvasDefinition->boardPatterns = [];
        $this->canvasDefinition->sectionPatterns = [];
        $this->canvasDefinition->projectCode = 'APP';
        $this->canvasDefinition->code = 'ONB';
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, new FixedRootDesignDirectoryReaderService($this->projectRootDirectory));
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, new class extends DesignStudioThumbnailsService {
            public function isRendererEnabled(): bool
            {
                return true;
            }
        });
        $testContainer->setTestInstance(DesignThemeProviderService::class, new class extends DesignThemeProviderService {
            public function getThemeStyleForReference(?string $themeReference): ?string
            {
                return $themeReference === 'brand:1:dark' ? '<style data-design-theme>:root{--p-500:#000}</style>' : null;
            }
        });
    }

    protected function tearDown(): void
    {
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, null);
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, null);
        $testContainer->setTestInstance(DesignThemeProviderService::class, null);
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    public function testABoardIsFoundByItsFileNameOrItsCode(): void
    {
        $designStudioBoardExportsService = new DesignStudioBoardExportsService();

        self::assertSame('onb-w-2-welcome.dc.html', $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'onb-w-2-welcome.dc.html')->boardKey);
        self::assertSame('onb-w-2-welcome.dc.html', $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'APP ONB-W2')->boardKey);
        self::assertSame('onb-w-2-welcome.dc.html', $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W2')->boardKey);
        $this->expectException(NotFoundException::class);
        $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W9');
    }

    public function testTheExportSendsTheBoardWithItsAssetsInlinedTheThemeAndTheRegion(): void
    {
        $designStudioBoardExportsService = $this->createServiceWithRecordingExport();
        $designDirectoryBoard = $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W2');

        $designBoardVectorExport = $designStudioBoardExportsService->exportBoardAsSvg(
            $this->canvasDefinition,
            $designDirectoryBoard,
            'brand:1:dark',
            DesignBoardExportRegion::fromRegionText('10,20,100,50')
        );

        $payload = $this->readLoadPayload($designBoardVectorExport);
        self::assertStringContainsString('src="data:image/png;base64,', $payload['body']['html'], 'the renderer loads nothing: asset files travel inside the board');
        self::assertSame([390, 844], [$payload['body']['width'], $payload['body']['height']]);
        self::assertSame('<style data-design-theme>:root{--p-500:#000}</style>', $payload['body']['theme']['css']);
        self::assertSame(DesignThemeProviderService::computeThemeHash('<style data-design-theme>:root{--p-500:#000}</style>'), $payload['body']['theme']['themeHash']);
        self::assertSame(['x' => 10, 'y' => 20, 'width' => 100, 'height' => 50], $payload['body']['region']);
        self::assertArrayNotHasKey('fullPage', $payload['body']);
        self::assertSame('none', $payload['headers']['Authorization'], 'never the application\'s own bearer token');
    }

    public function testAResponsiveBoardIsExportedInOneDeviceFrameAsAWholePage(): void
    {
        $designStudioBoardExportsService = $this->createServiceWithRecordingExport();
        $designDirectoryBoard = $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'home.html');

        $firstFrameExport = $designStudioBoardExportsService->exportBoardAsSvg($this->canvasDefinition, $designDirectoryBoard, null);
        $desktopExport = $designStudioBoardExportsService->exportBoardAsSvg($this->canvasDefinition, $designDirectoryBoard, null, null, 'desktop-1440');

        $firstFramePayload = $this->readLoadPayload($firstFrameExport);
        self::assertTrue($firstFramePayload['body']['fullPage']);
        self::assertSame('', $firstFramePayload['body']['theme']['css'], 'no theme: the board as authored');
        self::assertSame(1440, $this->readLoadPayload($desktopExport)['body']['width']);
        $this->expectException(BadRequestException::class);
        $designStudioBoardExportsService->exportBoardAsSvg($this->canvasDefinition, $designDirectoryBoard, null, null, 'no-such-device');
    }

    public function testAnUnknownThemeIsRefused(): void
    {
        $designStudioBoardExportsService = $this->createServiceWithRecordingExport();

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Unknown theme brand:9');
        $designStudioBoardExportsService->exportBoardAsSvg($this->canvasDefinition, $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W2'), 'brand:9');
    }

    public function testTheRenderersAnswerIsReadIntoTheExportAndAFailureIsKept(): void
    {
        $answeredExport = new ArgusDesignBoardVectorExport();
        $answer = (object)['status' => 200, 'data' => (object)[
            'svg' => base64_encode('<?xml version="1.0"?><svg width="100" height="50"></svg>'),
            'png' => base64_encode("\x89PNG raster"),
            'comparison' => base64_encode("\x89PNG comparison"),
            'differingPixelRatio' => 0.0533,
            'width' => 100,
            'height' => 50,
        ]];
        $answeredExport->handleLoadResponse($answer);
        self::assertNull($answeredExport->failureReason);
        self::assertStringContainsString('<svg width="100"', (string)$answeredExport->svg);
        self::assertSame("\x89PNG raster", $answeredExport->png);
        self::assertSame("\x89PNG comparison", $answeredExport->comparisonPng);
        self::assertSame(0.0533, $answeredExport->differingPixelRatio);
        self::assertSame([100, 50], [$answeredExport->exportWidth, $answeredExport->exportHeight]);

        $refusedExport = new ArgusDesignBoardVectorExport();
        $operationPayload = [];
        $apiOperation = new ArgusApiOperation($refusedExport, 'vector', 'POST:/designBoardRenderer/vector', $operationPayload);
        $apiOperation->responseStatusCode = 404;
        $refusal = (object)['status' => 404, 'error' => 'unknown job "vector"'];
        $refusedExport->handleLoadResponse($refusal, $apiOperation);
        self::assertNull($refusedExport->svg);
        self::assertNotNull($refusedExport->failureReason);
        self::assertTrue($refusedExport->isRetryableFailure(), 'an older renderer without the job answers 404');
    }

    public function testTheVectorEndpointIsTheThumbnailsEndpointWithTheVectorJob(): void
    {
        self::assertSame('POST:/designBoardRenderer/vector', ArgusDesignBoardVectorExport::getConfiguredEndpoint());
    }

    public function testARegionIsReadFromItsText(): void
    {
        self::assertSame('0,560,390,200', DesignBoardExportRegion::fromRegionText(' 0, 560 ,390,200 ')->toRegionText());
        $this->expectException(BadRequestException::class);
        DesignBoardExportRegion::fromRegionText('0,560,0,200');
    }

    public function testTheStudioPageGetsTheSvgWithItsSizesAndTheComparison(): void
    {
        $designStudioBoardExportsService = $this->createServiceWithRecordingExport();
        $designDirectoryBoard = $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W2');

        $designStudioBoardSvgExportView = $designStudioBoardExportsService->buildBoardSvgExportView(
            $this->canvasDefinition,
            $designDirectoryBoard,
            'brand:1:dark',
            DesignBoardExportRegion::fromRegionText('0,560,390,200')
        );

        self::assertSame('onb-w-2-welcome-brand-1-dark-0-560-390-200.svg', $designStudioBoardSvgExportView->fileName);
        self::assertSame(strlen((string)$designStudioBoardSvgExportView->svg), $designStudioBoardSvgExportView->svgByteSize);
        self::assertGreaterThan(0, $designStudioBoardSvgExportView->svgGzipByteSize);
        self::assertSame(strlen(self::ONE_PIXEL_PNG), $designStudioBoardSvgExportView->rasterPngByteSize);
        self::assertSame(0.25, $designStudioBoardSvgExportView->differingPixelRatio);
        self::assertStringStartsWith('data:image/png;base64,', (string)$designStudioBoardSvgExportView->comparisonImageDataUrl);
        self::assertNull($designStudioBoardSvgExportView->deviceKey);
    }

    public function testAFailedExportIsRefusedWithTheRenderersReason(): void
    {
        $designStudioBoardExportsService = new class extends DesignStudioBoardExportsService {
            protected function createVectorExport(): ArgusDesignBoardVectorExport
            {
                return new class extends ArgusDesignBoardVectorExport {
                    public function export(): void
                    {
                        $this->failureStatusCode = 404;
                        $this->failureReason = 'unknown job "vector"';
                    }
                };
            }
        };

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('The renderer could not export the board: unknown job "vector" (exporting needs renderer 1.2.0)');
        $designStudioBoardExportsService->buildBoardSvgExportView($this->canvasDefinition, $designStudioBoardExportsService->findBoard($this->canvasDefinition, 'W2'), null);
    }

    /** An exports service whose export records its payload instead of calling the renderer */
    protected function createServiceWithRecordingExport(): DesignStudioBoardExportsService
    {
        return new class extends DesignStudioBoardExportsService {
            protected function createVectorExport(): ArgusDesignBoardVectorExport
            {
                return new class extends ArgusDesignBoardVectorExport {
                    public function export(): void
                    {
                        $this->svg = '<svg width="390" height="200"/>';
                        $this->png = DesignStudioBoardExportsServiceTest::ONE_PIXEL_PNG;
                        $this->comparisonPng = DesignStudioBoardExportsServiceTest::ONE_PIXEL_PNG;
                        $this->differingPixelRatio = 0.25;
                        $this->exportWidth = 390;
                        $this->exportHeight = 200;
                    }
                };
            }
        };
    }

    /**
     * @return array<string, mixed> the body and headers the export would send
     */
    protected function readLoadPayload(object $designBoardVectorExport): array
    {
        return (new ReflectionMethod($designBoardVectorExport, 'getLoadPayload'))->invoke($designBoardVectorExport);
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }
}
