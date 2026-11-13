<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Tests\DesignStudio\Support\FilesystemDesignStudioFilesService;
use Imagick;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The preview copy of a thumbnail: at most 640 px wide, WebP (JPEG without WebP), same aspect ratio, found again by the
 * thumbnail key — a long page that weighs megabytes as PNG stays small on the canvas.
 */
class DesignStudioPreviewThumbnailsTest extends TestCase
{
    protected string $storageDirectory;

    protected function setUp(): void
    {
        if (!class_exists(Imagick::class)) {
            $this->markTestSkipped('needs the imagick extension');
        }
        $this->storageDirectory = sys_get_temp_dir() . '/design-studio-preview-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageDirectory)) {
            (new Filesystem())->remove($this->storageDirectory);
        }
    }

    protected function createPng(int $width, int $height): string
    {
        $imagick = new Imagick();
        $imagick->newPseudoImage($width, $height, 'gradient:red-blue');
        $imagick->setImageFormat('png');
        $png = $imagick->getImageBlob();
        $imagick->clear();
        return $png;
    }

    public function testALongPageGetsASmallPreviewFoundByItsKey(): void
    {
        $designStudioFilesService = new FilesystemDesignStudioFilesService($this->storageDirectory);
        $thumbnailKey = str_repeat('a', 64);
        $png = $this->createPng(1440, 6000);

        $this->assertNull($designStudioFilesService->readPreviewThumbnail($thumbnailKey));
        $preview = $designStudioFilesService->storePreviewThumbnail($thumbnailKey, $png);

        $this->assertSame($preview, $designStudioFilesService->readPreviewThumbnail($thumbnailKey));
        $imageSize = getimagesizefromstring((string)$preview);
        $this->assertSame([640, 2667], [$imageSize[0], $imageSize[1]]);
        $this->assertContains($imageSize['mime'], ['image/webp', 'image/jpeg']);
        $this->assertLessThan(strlen($png), strlen((string)$preview));
    }

    public function testANarrowBoardIsNotEnlarged(): void
    {
        $designStudioFilesService = new FilesystemDesignStudioFilesService($this->storageDirectory);
        $preview = $designStudioFilesService->storePreviewThumbnail(str_repeat('b', 64), $this->createPng(390, 844));
        $imageSize = getimagesizefromstring((string)$preview);
        $this->assertSame([390, 844], [$imageSize[0], $imageSize[1]]);
    }
}
