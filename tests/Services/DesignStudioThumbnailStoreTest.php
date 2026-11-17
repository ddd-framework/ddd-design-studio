<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Services\DesignStudioFilesService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Tests\DesignStudio\Support\FilesystemDesignStudioFilesService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A thumbnail exists once its full picture is stored: a small or preview copy that cannot be stored (a hanging or
 * failing upload) never makes the board count as missing; the copy is made on its first read.
 */
class DesignStudioThumbnailStoreTest extends TestCase
{
    protected string $storageDirectory;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('needs the gd extension');
        }
        $this->storageDirectory = sys_get_temp_dir() . '/design-studio-thumbnail-store-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->getTestContainer()->setTestInstance(DesignStudioFilesService::class, null);
        if (isset($this->storageDirectory)) {
            (new Filesystem())->remove($this->storageDirectory);
        }
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }

    protected function createPng(): string
    {
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
    }

    public function testTheRowIsWrittenOnceTheFullPictureIsStoredEvenWhenTheCopiesFail(): void
    {
        $this->getTestContainer()->setTestInstance(DesignStudioFilesService::class, new class($this->storageDirectory) extends FilesystemDesignStudioFilesService {
            public function storeSmallThumbnail(string $thumbnailKey, string $png): ?string
            {
                throw new InternalErrorException('Object storage "test": put failed: Idle timeout reached');
            }

            public function storePreviewThumbnail(string $thumbnailKey, string $png): ?string
            {
                throw new InternalErrorException('Object storage "test": put failed: Idle timeout reached');
            }
        });
        $designStudioThumbnailsService = new class extends DesignStudioThumbnailsService {
            /** @var DesignStudioThumbnail[] the rows written */
            public array $writtenThumbnails = [];

            public function findByThumbnailKey(string $thumbnailKey): ?DesignStudioThumbnail
            {
                return null;
            }

            public function update(DefaultObject $entity, int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): DefaultObject
            {
                $this->writtenThumbnails[] = clone $entity;
                return $entity;
            }

            protected function writeSmallObjectKey(DesignStudioThumbnail $designStudioThumbnail): void
            {
                $this->writtenThumbnails[] = clone $designStudioThumbnail;
            }

            protected function logSmallerCopyFailure(string $thumbnailKey, string $size, \Throwable $throwable): void
            {
            }
        };
        $designDirectoryBoard = new DesignDirectoryBoard();
        $designDirectoryBoard->boardKey = 'home.html';
        $designDirectoryBoard->contentHash = str_repeat('c', 40);

        $designStudioThumbnail = $designStudioThumbnailsService->storeThumbnail($designDirectoryBoard, str_repeat('t', 64), $this->createPng());

        $this->assertNotNull($designStudioThumbnail);
        $this->assertCount(1, $designStudioThumbnailsService->writtenThumbnails, 'one row, written right after the full picture');
        $this->assertNotNull($designStudioThumbnail->objectKey);
        $this->assertNull($designStudioThumbnail->smallObjectKey);
        $this->assertSame([400, 300], [$designStudioThumbnail->width, $designStudioThumbnail->height]);
        $this->assertFileExists($this->storageDirectory . '/' . $designStudioThumbnail->objectKey);
    }

    public function testASecondAttemptDoesNotUploadAStoredFullPictureAgain(): void
    {
        $designStudioFilesService = new class($this->storageDirectory) extends FilesystemDesignStudioFilesService {
            /** @var string[] keys of every upload */
            public array $uploadedObjectKeys = [];

            protected function getObjectStorage(): ?ObjectStorage
            {
                $uploadedObjectKeys = &$this->uploadedObjectKeys;
                return new class($this->testStorageDirectory, $uploadedObjectKeys) extends FilesystemObjectStorage {
                    public function __construct(string $rootDirectory, protected array &$testUploadedObjectKeys)
                    {
                        parent::__construct($rootDirectory);
                    }

                    public function putObject(string $key, string $content, ?string $contentType = null): void
                    {
                        $this->testUploadedObjectKeys[] = $key;
                        parent::putObject($key, $content, $contentType);
                    }
                };
            }
        };
        $thumbnailKey = str_repeat('a', 64);

        $firstObjectKey = $designStudioFilesService->storeThumbnail($thumbnailKey, $this->createPng());
        $secondObjectKey = $designStudioFilesService->storeThumbnail($thumbnailKey, $this->createPng());

        $this->assertSame($firstObjectKey, $secondObjectKey);
        $this->assertSame([$firstObjectKey], $designStudioFilesService->uploadedObjectKeys);
    }

    public function testTheExistenceCheckOfTheFullPictureUsesTheThumbnailStoreAndItsFailureIsThrown(): void
    {
        $designStudioFilesService = new class($this->storageDirectory) extends FilesystemDesignStudioFilesService {
            public bool $mainStoreUsed = false;

            protected function getObjectStorage(): ?ObjectStorage
            {
                $this->mainStoreUsed = true;
                return parent::getObjectStorage();
            }

            protected function getThumbnailObjectStorage(): ?ObjectStorage
            {
                return new class($this->testStorageDirectory) extends FilesystemObjectStorage {
                    public function objectExists(string $key): bool
                    {
                        throw new InternalErrorException("Object storage \"thumbnails\": check of $key failed: Could not contact remote server");
                    }
                };
            }
        };

        try {
            $designStudioFilesService->storeThumbnail(str_repeat('a', 64), $this->createPng());
            $this->fail('the failed check is thrown to the picture\'s try');
        } catch (InternalErrorException $internalErrorException) {
            $this->assertStringContainsString('"thumbnails": check of', $internalErrorException->getMessage());
        }
        $this->assertFalse($designStudioFilesService->mainStoreUsed, 'the check never goes to the main store');
    }
}
