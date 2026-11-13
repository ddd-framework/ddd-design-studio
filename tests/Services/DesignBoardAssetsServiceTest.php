<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Services\DesignBoardAssetsService;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioFilesService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Tests\DesignStudio\Support\FilesystemDesignStudioFilesService;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boards load images from the canvas' `assets/` folder: references are found in attributes, srcset and CSS url(),
 * rewritten to the content route with token and version, inlined for the renderer, delivered only from inside
 * `assets/`, versioned per board (assets hash) and kept with snapshots for earlier versions.
 */
class DesignBoardAssetsServiceTest extends TestCase
{
    protected string $projectRootDirectory;

    protected string $storageDirectory;

    protected FixedRootDesignDirectoryReaderService $designDirectoryReaderService;

    protected function setUp(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/design-studio-assets-test-' . bin2hex(random_bytes(4));
        $this->projectRootDirectory = $temporaryDirectory . '/project';
        $this->storageDirectory = $temporaryDirectory . '/storage';
        mkdir($this->projectRootDirectory . '/design/canvas/assets/photos', 0777, true);
        mkdir($this->storageDirectory, 0777, true);
        $this->designDirectoryReaderService = new FixedRootDesignDirectoryReaderService($this->projectRootDirectory, 1000);
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, $this->designDirectoryReaderService);
        $testContainer->setTestInstance(DesignStudioFilesService::class, new FilesystemDesignStudioFilesService($this->storageDirectory));
    }

    protected function tearDown(): void
    {
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, null);
        $testContainer->setTestInstance(DesignStudioFilesService::class, null);
        (new Filesystem())->remove(dirname($this->projectRootDirectory));
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }

    protected function createCanvasDefinition(): DesignCanvasDefinition
    {
        $canvasDefinition = new DesignCanvasDefinition();
        $canvasDefinition->projectKey = 'app';
        $canvasDefinition->canvasKey = 'test';
        $canvasDefinition->folder = 'design/canvas';
        $canvasDefinition->boardPatterns = [];
        $canvasDefinition->sectionPatterns = [];
        return $canvasDefinition;
    }

    protected function writeCanvasFile(string $relativePath, string $content): void
    {
        file_put_contents($this->projectRootDirectory . '/design/canvas/' . $relativePath, $content);
    }

    protected function createService(): DesignBoardAssetsService
    {
        return new DesignBoardAssetsService();
    }

    public function testFindsReferencesInAttributesSrcsetAndCssOnly(): void
    {
        $html = '<img src="assets/photos/hero.webp"><img src=\'./assets/a.png\'>'
            . '<img srcset="assets/s-1.webp 1x, ./assets/s-2.webp 2x">'
            . '<div style="background: url(assets/bg.jpg)"></div><style>.x{background:url("./assets/b.avif")}</style>'
            . '<a href="assets/icon.svg">x</a><p>see assets/text.png in the text</p>';

        $this->assertSame(
            ['s-1.webp', 's-2.webp', 'photos/hero.webp', 'a.png', 'bg.jpg', 'b.avif', 'icon.svg'],
            DesignBoardAssetsService::findReferencedAssetPaths($html)
        );
    }

    public function testInvalidPathsAreNoAssets(): void
    {
        $html = '<img src="assets/../canvas.json"><img src="assets/.hidden.png"><img src="assets/photos/../a.png">'
            . '<img src="assets/data.json"><img src="assets/photos/.git/x.png"><img src="/assets/root.png">';

        $this->assertSame([], DesignBoardAssetsService::findReferencedAssetPaths($html));
        $this->assertFalse(DesignBoardAssetsService::isValidAssetPath('photos//a.png'));
        $this->assertTrue(DesignBoardAssetsService::isValidAssetPath('photos/start-hero_m.1.WEBP'));
    }

    public function testReferencesPointAtTheContentRouteWithTokenAndVersion(): void
    {
        $this->writeCanvasFile('assets/photos/hero.webp', 'hero-bytes');
        $this->writeCanvasFile('assets/s-2.webp', 'retina');
        $html = '<img src="assets/photos/hero.webp"><img srcset="assets/photos/hero.webp 1x, assets/s-2.webp 2x">'
            . '<div style="background:url(\'./assets/photos/hero.webp\')"></div><img src="assets/missing.png">';
        $designBoardAssetsService = $this->createService();
        $designBoardAssetReferences = $designBoardAssetsService->findCurrentAssetReferences(
            $this->createCanvasDefinition(),
            DesignBoardAssetsService::findReferencedAssetPaths($html)
        );

        $rewrittenHtml = $designBoardAssetsService->rewriteAssetReferencesToContentRoute(
            $html,
            $designBoardAssetReferences,
            '/content/assets/app/test',
            'token=t0k'
        );

        $heroUrl = '/content/assets/app/test/photos/hero.webp?token=t0k&v=' . DesignDirectoryReaderService::computeContentHash('hero-bytes');
        $retinaUrl = '/content/assets/app/test/s-2.webp?token=t0k&v=' . DesignDirectoryReaderService::computeContentHash('retina');
        $this->assertSame(
            '<img src="' . $heroUrl . '"><img srcset="' . $heroUrl . ' 1x, ' . $retinaUrl . ' 2x">'
            . '<div style="background:url(\'' . $heroUrl . '\')"></div><img src="assets/missing.png">',
            $rewrittenHtml
        );
    }

    public function testTheRendererGetsTheFilesAsDataUris(): void
    {
        $this->writeCanvasFile('assets/photos/hero.webp', 'hero-bytes');

        $inlinedHtml = $this->createService()->inlineAssetReferences(
            '<img src="assets/photos/hero.webp"><i style="background:url(assets/photos/hero.webp)"></i>',
            $this->createCanvasDefinition()
        );

        $dataUri = 'data:image/webp;base64,' . base64_encode('hero-bytes');
        $this->assertSame('<img src="' . $dataUri . '"><i style="background:url(' . $dataUri . ')"></i>', $inlinedHtml);
    }

    public function testOnlyFilesInsideTheAssetsFolderAreDelivered(): void
    {
        $canvasDefinition = $this->createCanvasDefinition();
        $this->writeCanvasFile('assets/photos/hero.webp', 'hero-bytes');
        $this->writeCanvasFile('assets/large.png', str_repeat('x', 1001));
        file_put_contents($this->projectRootDirectory . '/secret.png', 'secret');
        symlink($this->projectRootDirectory . '/secret.png', $this->projectRootDirectory . '/design/canvas/assets/link.png');
        $designBoardAssetsService = $this->createService();

        $designBoardAsset = $designBoardAssetsService->readAsset($canvasDefinition, 'photos/hero.webp');
        $this->assertSame('hero-bytes', $designBoardAsset?->content);
        $this->assertSame('image/webp', $designBoardAsset?->mediaType);
        $this->assertNull($designBoardAssetsService->readAsset($canvasDefinition, 'link.png'));
        $this->assertNull($designBoardAssetsService->readAsset($canvasDefinition, 'large.png'));
        $this->assertNull($designBoardAssetsService->readAsset($canvasDefinition, '../canvas.json'));
        $this->assertNull($designBoardAssetsService->readAsset($canvasDefinition, 'photos/hero.webp', str_repeat('a', 40)));
    }

    public function testTheAssetsHashOfABoardChangesWhenAFileIsReplaced(): void
    {
        $canvasDefinition = $this->createCanvasDefinition();
        $this->writeCanvasFile('canvas.json', '{"boards":{"a.html":{},"b.html":{}}}');
        $this->writeCanvasFile('a.html', '<img src="assets/photos/hero.webp">');
        $this->writeCanvasFile('b.html', '<p>no images</p>');
        $this->writeCanvasFile('assets/photos/hero.webp', 'first');

        $boardsBefore = $this->designDirectoryReaderService->readCanvas($canvasDefinition)->boards;
        $this->writeCanvasFile('assets/photos/hero.webp', 'second photo');
        $boardsAfter = $this->designDirectoryReaderService->readCanvas($canvasDefinition)->boards;

        $this->assertNotNull($boardsBefore->getElements()[0]->assetsHash);
        $this->assertNotSame($boardsBefore->getElements()[0]->assetsHash, $boardsAfter->getElements()[0]->assetsHash);
        $this->assertSame($boardsBefore->getElements()[0]->contentHash, $boardsAfter->getElements()[0]->contentHash);
        $this->assertNull($boardsAfter->getElements()[1]->assetsHash);
    }

    public function testAThumbnailKeyWithoutAssetsStaysAsBefore(): void
    {
        $designStudioThumbnailsService = new DesignStudioThumbnailsService();
        $contentHash = str_repeat('c', 40);

        $this->assertSame(
            hash('sha256', $contentHash . ':theme:' . $designStudioThumbnailsService->getRendererVersion()),
            $designStudioThumbnailsService->computeThumbnailKey($contentHash, 'theme')
        );
        $this->assertNotSame(
            $designStudioThumbnailsService->computeThumbnailKey($contentHash, 'theme'),
            $designStudioThumbnailsService->computeThumbnailKey($contentHash, 'theme', str_repeat('a', 40))
        );
    }

    public function testAnEarlierCommentedVersionShowsTheImagesItHad(): void
    {
        $canvasDefinition = $this->createCanvasDefinition();
        $oldBoardContent = '<img src="assets/photos/hero.webp">';
        $this->writeCanvasFile('a.html', $oldBoardContent);
        $this->writeCanvasFile('assets/photos/hero.webp', 'old photo');
        $oldContentHash = DesignDirectoryReaderService::computeContentHash($oldBoardContent);
        $designBoardAssetsService = $this->createService();
        $designBoardAssetsService->storeAssetSnapshotsForBoardVersion($canvasDefinition, $oldContentHash, $oldBoardContent);

        // the board and its photo change after the comment
        $this->writeCanvasFile('a.html', '<img src="assets/photos/hero.webp"><p>v2</p>');
        $this->writeCanvasFile('assets/photos/hero.webp', 'new photo');

        $oldPhotoHash = DesignDirectoryReaderService::computeContentHash('old photo');
        $oldVersionReferences = $designBoardAssetsService->findAssetReferencesForBoardVersion($canvasDefinition, 'a.html', $oldContentHash, $oldBoardContent);
        $this->assertSame($oldPhotoHash, $oldVersionReferences->getByAssetPath('photos/hero.webp')?->contentHash);
        // the old file is delivered from the snapshot, the current one from the checkout
        $this->assertSame('old photo', $designBoardAssetsService->readAsset($canvasDefinition, 'photos/hero.webp', $oldPhotoHash)?->content);
        $this->assertSame('new photo', $designBoardAssetsService->readAsset($canvasDefinition, 'photos/hero.webp')?->content);
        // the checkout's version shows the checkout's files
        $currentBoardContent = '<img src="assets/photos/hero.webp"><p>v2</p>';
        $currentVersionReferences = $designBoardAssetsService->findAssetReferencesForBoardVersion(
            $canvasDefinition,
            'a.html',
            DesignDirectoryReaderService::computeContentHash($currentBoardContent),
            $currentBoardContent
        );
        $this->assertSame(
            DesignDirectoryReaderService::computeContentHash('new photo'),
            $currentVersionReferences->getByAssetPath('photos/hero.webp')?->contentHash
        );
    }
}
