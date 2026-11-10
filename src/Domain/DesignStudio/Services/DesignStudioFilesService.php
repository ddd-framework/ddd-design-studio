<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use Imagick;
use ImagickException;

/**
 * Files the design studio keeps itself, in a named store of the framework's object storage (config
 * `DesignStudio.storage`): snapshots of board versions a comment was written on
 * (content-addressed by the board's content hash: written once, shared by every thread on that version) and rendered
 * thumbnails. Without a configured store nothing is kept and reads answer null.
 */
class DesignStudioFilesService extends Service
{
    /** @var string Fallback of the config `DesignStudio.storage.store` */
    public const string DEFAULT_STORE_NAME = 'design-studio';

    /** @var string Fallback of the config `DesignStudio.storage.prefix` */
    public const string DEFAULT_KEY_PREFIX = 'design-studio';

    /** @var int Width in pixels of the reduced copy of a thumbnail */
    public const int SMALL_THUMBNAIL_WIDTH = 120;

    /**
     * Keeps the content of a board version.
     * @param string $contentHash content hash of the board file (git blob hash)
     * @param string $content the board file
     * @return string|null the object key, null when no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function storeSnapshot(string $contentHash, string $content): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null) {
            return null;
        }
        $objectKey = $this->getSnapshotObjectKey($contentHash);
        if (!$objectStorage->objectExists($objectKey)) {
            $objectStorage->putObject($objectKey, $content, 'text/html; charset=utf-8');
        }
        return $objectKey;
    }

    /**
     * @param string $objectKey a key returned by storeSnapshot()
     * @return string|null the board file, null when it is missing or no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function readSnapshot(string $objectKey): ?string
    {
        if (!$this->isSnapshotObjectKey($objectKey)) {
            return null;
        }
        return $this->getObjectStorage()?->getObjectContent($objectKey);
    }

    /**
     * @param string $objectKey a key returned by storeSnapshot()
     * @return void
     * @throws InternalErrorException when the store fails
     */
    public function deleteSnapshot(string $objectKey): void
    {
        if ($this->isSnapshotObjectKey($objectKey)) {
            $this->getObjectStorage()?->deleteObject($objectKey);
        }
    }

    /**
     * Keeps a rendered thumbnail.
     * @param string $thumbnailKey SHA-256 that identifies the thumbnail
     * @param string $png PNG bytes
     * @return string|null the object key, null when no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function storeThumbnail(string $thumbnailKey, string $png): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{64}$/', $thumbnailKey)) {
            return null;
        }
        $objectKey = $this->getKeyPrefix() . '/thumbnails/' . $thumbnailKey . '.png';
        $objectStorage->putObject($objectKey, $png, 'image/png');
        return $objectKey;
    }

    /**
     * Keeps a reduced copy (SMALL_THUMBNAIL_WIDTH wide, same aspect ratio) of a thumbnail for far zoom levels.
     * @param string $thumbnailKey SHA-256 that identifies the thumbnail
     * @param string $png PNG bytes of the full thumbnail
     * @return string|null the object key, null when no store is configured or the image library (Imagick) is missing
     * @throws InternalErrorException when the store fails
     * @throws ImagickException
     */
    public function storeSmallThumbnail(string $thumbnailKey, string $png): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{64}$/', $thumbnailKey) || !class_exists(Imagick::class)) {
            return null;
        }
        $imagick = new Imagick();
        $imagick->readImageBlob($png);
        $imagick->thumbnailImage(static::SMALL_THUMBNAIL_WIDTH, 0);
        $imagick->stripImage();
        $imagick->setImageFormat('png');
        $smallPng = $imagick->getImageBlob();
        $imagick->clear();
        $objectKey = $this->getKeyPrefix() . '/thumbnails/' . $thumbnailKey . '-w' . static::SMALL_THUMBNAIL_WIDTH . '.png';
        $objectStorage->putObject($objectKey, $smallPng, 'image/png');
        return $objectKey;
    }

    /**
     * @param string $objectKey a key returned by storeThumbnail() or storeSmallThumbnail()
     * @return string|null PNG bytes, null when it is missing or no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function readThumbnail(string $objectKey): ?string
    {
        if (!preg_match('#^' . preg_quote($this->getKeyPrefix(), '#') . '/thumbnails/[0-9a-f]{64}(-w\d+)?\.png$#', $objectKey)) {
            return null;
        }
        return $this->getObjectStorage()?->getObjectContent($objectKey);
    }

    /**
     * @param string $contentHash
     * @return string
     */
    public function getSnapshotObjectKey(string $contentHash): string
    {
        return $this->getKeyPrefix() . '/snapshots/' . $contentHash . '.html';
    }

    /**
     * @param string $objectKey
     * @return bool true for a key of this service's snapshot area (never a foreign key of the shared store)
     */
    protected function isSnapshotObjectKey(string $objectKey): bool
    {
        return (bool)preg_match('#^' . preg_quote($this->getKeyPrefix(), '#') . '/snapshots/[0-9a-f]{40}\.html$#', $objectKey);
    }

    /**
     * @return string
     */
    protected function getKeyPrefix(): string
    {
        return trim((string)(Config::get('DesignStudio.storage.prefix') ?? static::DEFAULT_KEY_PREFIX), '/');
    }

    /**
     * @return ObjectStorage|null null when the store is not configured
     * @throws InternalErrorException
     */
    protected function getObjectStorage(): ?ObjectStorage
    {
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->getObjectStorage((string)(Config::get('DesignStudio.storage.store') ?? static::DEFAULT_STORE_NAME));
    }
}
