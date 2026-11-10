<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioFilesService;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use PHPUnit\Framework\TestCase;

/**
 * DesignStudioFilesService keeps one snapshot per board version and never touches keys outside its snapshot area.
 */
class DesignStudioFilesServiceTest extends TestCase
{
    protected function createService(?ObjectStorage $objectStorage): DesignStudioFilesService
    {
        return new class($objectStorage) extends DesignStudioFilesService {
            public function __construct(protected ?ObjectStorage $testObjectStorage)
            {
            }

            protected function getObjectStorage(): ?ObjectStorage
            {
                return $this->testObjectStorage;
            }

            protected function getKeyPrefix(): string
            {
                return 'design-studio';
            }
        };
    }

    public function testStoresASnapshotOncePerContentHash(): void
    {
        $content = '<html>board</html>';
        $contentHash = DesignDirectoryReaderService::computeContentHash($content);
        $objectKey = "design-studio/snapshots/$contentHash.html";
        $objectStorage = $this->createMock(ObjectStorage::class);
        $objectStorage->method('objectExists')->willReturnOnConsecutiveCalls(false, true);
        $objectStorage->expects($this->once())->method('putObject')->with($objectKey, $content, 'text/html; charset=utf-8');
        $designStudioFilesService = $this->createService($objectStorage);
        $this->assertSame($objectKey, $designStudioFilesService->storeSnapshot($contentHash, $content));
        $this->assertSame($objectKey, $designStudioFilesService->storeSnapshot($contentHash, $content));
    }

    public function testWithoutAStoreNothingIsKept(): void
    {
        $designStudioFilesService = $this->createService(null);
        $this->assertNull($designStudioFilesService->storeSnapshot(str_repeat('a', 40), 'x'));
        $this->assertNull($designStudioFilesService->readSnapshot('design-studio/snapshots/' . str_repeat('a', 40) . '.html'));
    }

    public function testIgnoresKeysOutsideTheSnapshotArea(): void
    {
        $objectStorage = $this->createMock(ObjectStorage::class);
        $objectStorage->expects($this->never())->method('getObjectContent');
        $objectStorage->expects($this->never())->method('deleteObject');
        $designStudioFilesService = $this->createService($objectStorage);
        foreach (['other/1/logo.png', 'design-studio/snapshots/../x.html', 'design-studio/snapshots/abc.html'] as $foreignObjectKey) {
            $this->assertNull($designStudioFilesService->readSnapshot($foreignObjectKey));
            $designStudioFilesService->deleteSnapshot($foreignObjectKey);
        }
    }

    public function testReadsAndDeletesItsOwnSnapshots(): void
    {
        $objectKey = 'design-studio/snapshots/' . str_repeat('b', 40) . '.html';
        $objectStorage = $this->createMock(ObjectStorage::class);
        $objectStorage->method('getObjectContent')->with($objectKey)->willReturn('<html></html>');
        $objectStorage->expects($this->once())->method('deleteObject')->with($objectKey);
        $designStudioFilesService = $this->createService($objectStorage);
        $this->assertSame('<html></html>', $designStudioFilesService->readSnapshot($objectKey));
        $designStudioFilesService->deleteSnapshot($objectKey);
    }
}
