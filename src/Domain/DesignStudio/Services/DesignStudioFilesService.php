<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Assets\DesignBoardAssetReference;
use DDD\Domain\DesignStudio\Entities\Assets\DesignBoardAssetReferences;
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
 * (content-addressed by the board's content hash: written once, shared by every thread on that version), the asset
 * files such a version references (content-addressed by their own hash, with a manifest per board version) and rendered
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

    /** @var int Width of the preview copy of a thumbnail (canvas zoom levels below the full picture), in pixels */
    public const int PREVIEW_THUMBNAIL_WIDTH = 640;

    /** @var int Quality of the preview copy (WebP, else JPEG) */
    public const int PREVIEW_THUMBNAIL_QUALITY = 78;

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
     * Keeps an asset file a commented board version references, content-addressed (written once, shared by every
     * board and version that shows it).
     * @param string $contentHash git blob hash of the file
     * @param string $content
     * @param string $mediaType
     * @return string|null the object key, null when no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function storeAssetSnapshot(string $contentHash, string $content, string $mediaType): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            return null;
        }
        $objectKey = $this->getAssetSnapshotObjectKey($contentHash);
        if (!$objectStorage->objectExists($objectKey)) {
            $objectStorage->putObject($objectKey, $content, $mediaType);
        }
        return $objectKey;
    }

    /**
     * @param string $contentHash git blob hash of the file
     * @return string|null the file, null when it was not kept or no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function readAssetSnapshot(string $contentHash): ?string
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            return null;
        }
        return $this->getObjectStorage()?->getObjectContent($this->getAssetSnapshotObjectKey($contentHash));
    }

    /**
     * Keeps which asset files a board version showed (next to the board snapshot).
     * @param string $contentHash content hash of the board version
     * @param DesignBoardAssetReferences $designBoardAssetReferences
     * @return string|null the object key, null when no store is configured
     * @throws InternalErrorException when the store fails
     */
    public function storeAssetManifest(string $contentHash, DesignBoardAssetReferences $designBoardAssetReferences): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            return null;
        }
        $contentHashesByAssetPath = [];
        foreach ($designBoardAssetReferences->getElements() as $designBoardAssetReference) {
            $contentHashesByAssetPath[(string)$designBoardAssetReference->assetPath] = $designBoardAssetReference->contentHash;
        }
        $objectKey = $this->getAssetManifestObjectKey($contentHash);
        $objectStorage->putObject($objectKey, (string)json_encode($contentHashesByAssetPath, JSON_UNESCAPED_SLASHES), 'application/json');
        return $objectKey;
    }

    /**
     * @param string $contentHash content hash of the board version
     * @return DesignBoardAssetReferences|null the asset files kept for that version, null when none were kept
     * @throws InternalErrorException when the store fails
     */
    public function readAssetManifest(string $contentHash): ?DesignBoardAssetReferences
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            return null;
        }
        $manifestContent = $this->getObjectStorage()?->getObjectContent($this->getAssetManifestObjectKey($contentHash));
        $contentHashesByAssetPath = $manifestContent === null ? null : json_decode($manifestContent, true);
        if (!is_array($contentHashesByAssetPath)) {
            return null;
        }
        $designBoardAssetReferences = new DesignBoardAssetReferences();
        foreach ($contentHashesByAssetPath as $assetPath => $assetContentHash) {
            if (!is_string($assetPath) || !is_string($assetContentHash) || !preg_match('/^[0-9a-f]{40}$/', $assetContentHash)) {
                continue;
            }
            $designBoardAssetReference = new DesignBoardAssetReference();
            $designBoardAssetReference->assetPath = $assetPath;
            $designBoardAssetReference->contentHash = $assetContentHash;
            $designBoardAssetReferences->add($designBoardAssetReference);
        }
        return $designBoardAssetReferences;
    }

    /**
     * @param string $contentHash content hash of the board version
     * @return void
     * @throws InternalErrorException when the store fails
     */
    public function deleteAssetManifest(string $contentHash): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            $this->getObjectStorage()?->deleteObject($this->getAssetManifestObjectKey($contentHash));
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
     * Keeps a preview copy of a thumbnail for the canvas: at most PREVIEW_THUMBNAIL_WIDTH wide (same aspect ratio),
     * WebP (JPEG on white when the image library has no WebP). A long page that weighs megabytes as PNG is a few
     * hundred kilobytes here. Found by its thumbnail key (readPreviewThumbnail()); no column holds its object key.
     * @param string $thumbnailKey SHA-256 that identifies the thumbnail
     * @param string $png PNG bytes of the full thumbnail
     * @return string|null the preview's bytes, null when no store is configured or the image library (Imagick) is missing
     * @throws InternalErrorException when the store fails
     * @throws ImagickException
     */
    public function storePreviewThumbnail(string $thumbnailKey, string $png): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{64}$/', $thumbnailKey) || !class_exists(Imagick::class)) {
            return null;
        }
        $isWebpAvailable = Imagick::queryFormats('WEBP') !== [];
        $imagick = new Imagick();
        $imagick->readImageBlob($png);
        if ($imagick->getImageWidth() > static::PREVIEW_THUMBNAIL_WIDTH) {
            $imagick->thumbnailImage(static::PREVIEW_THUMBNAIL_WIDTH, 0);
        }
        $imagick->stripImage();
        if (!$isWebpAvailable) {
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }
        $imagick->setImageFormat($isWebpAvailable ? 'webp' : 'jpeg');
        $imagick->setImageCompressionQuality(static::PREVIEW_THUMBNAIL_QUALITY);
        $preview = $imagick->getImageBlob();
        $imagick->clear();
        $objectKey = $this->getPreviewThumbnailObjectKey($thumbnailKey, $isWebpAvailable ? 'webp' : 'jpg');
        $objectStorage->putObject($objectKey, $preview, $isWebpAvailable ? 'image/webp' : 'image/jpeg');
        return $preview;
    }

    /**
     * @param string $thumbnailKey
     * @return string|null the preview copy's bytes (WebP or JPEG), null when there is none (yet)
     * @throws InternalErrorException when the store fails
     */
    public function readPreviewThumbnail(string $thumbnailKey): ?string
    {
        $objectStorage = $this->getObjectStorage();
        if ($objectStorage === null || !preg_match('/^[0-9a-f]{64}$/', $thumbnailKey)) {
            return null;
        }
        foreach (['webp', 'jpg'] as $extension) {
            $preview = $objectStorage->getObjectContent($this->getPreviewThumbnailObjectKey($thumbnailKey, $extension));
            if ($preview !== null) {
                return $preview;
            }
        }
        return null;
    }

    /**
     * @param string $thumbnailKey
     * @param string $extension `webp` or `jpg`
     * @return string
     */
    public function getPreviewThumbnailObjectKey(string $thumbnailKey, string $extension): string
    {
        return $this->getKeyPrefix() . '/thumbnails/' . $thumbnailKey . '-w' . static::PREVIEW_THUMBNAIL_WIDTH . '.' . $extension;
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
     * @param string $contentHash git blob hash of an asset file
     * @return string
     */
    public function getAssetSnapshotObjectKey(string $contentHash): string
    {
        return $this->getKeyPrefix() . '/snapshots/assets/' . $contentHash;
    }

    /**
     * @param string $contentHash content hash of a board version
     * @return string
     */
    public function getAssetManifestObjectKey(string $contentHash): string
    {
        return $this->getKeyPrefix() . '/snapshots/' . $contentHash . '.assets.json';
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
