<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\DBDesignStudioThumbnail;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\DBDesignStudioThumbnails;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use ImagickException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Rendered pictures of boards. Only used when the renderer is switched on (config `DesignStudio.renderer.enabled`);
 * without it the studio shows no thumbnails and nothing here is called. A thumbnail belongs to a board version in a
 * look, not to a board: its key is derived from the board's content hash, the theme hash and the renderer version, so
 * nothing has to be invalidated — a changed board simply has no thumbnail yet.
 *
 * @method DesignStudioThumbnail find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioThumbnails findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioThumbnail getEntityRepoClassInstance()
 * @method DBDesignStudioThumbnails getEntitySetRepoClassInstance()
 */
class DesignStudioThumbnailsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioThumbnail::class;

    /** @var string Fallback of the config `DesignStudio.renderer.rendererVersion` */
    public const string DEFAULT_RENDERER_VERSION = '1';

    /**
     * @return bool thumbnails are rendered and shown
     */
    public function isRendererEnabled(): bool
    {
        return (bool)(Config::get('DesignStudio.renderer.enabled') ?? false);
    }

    /**
     * @return string the configured renderer version (part of every thumbnail key)
     */
    public function getRendererVersion(): string
    {
        return (string)(Config::get('DesignStudio.renderer.rendererVersion') ?: static::DEFAULT_RENDERER_VERSION);
    }

    /**
     * @param string $contentHash content hash of the board file
     * @param string $themeHash DesignThemeProviderService::computeThemeHash()
     * @return string the key of the board version's thumbnail in that look
     */
    public function computeThumbnailKey(string $contentHash, string $themeHash): string
    {
        return hash('sha256', $contentHash . ':' . $themeHash . ':' . $this->getRendererVersion());
    }

    /**
     * @param string $thumbnailKey
     * @return DesignStudioThumbnail|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByThumbnailKey(string $thumbnailKey): ?DesignStudioThumbnail
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.thumbnailKey = :thumbnailKey")->setParameter('thumbnailKey', $thumbnailKey);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * @param DesignDirectoryBoards $designDirectoryBoards boards of the checkout
     * @param string $themeHash
     * @return DesignStudioThumbnails the existing thumbnails of these board versions in the look
     *                                (DesignStudioThumbnails::getByThumbnailKey)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findForBoards(DesignDirectoryBoards $designDirectoryBoards, string $themeHash): DesignStudioThumbnails
    {
        $thumbnailKeys = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            if ($designDirectoryBoard->contentHash !== null) {
                $thumbnailKeys[] = $this->computeThumbnailKey($designDirectoryBoard->contentHash, $themeHash);
            }
        }
        if (!$thumbnailKeys) {
            return new DesignStudioThumbnails();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.thumbnailKey IN (:thumbnailKeys)")
            ->setParameter('thumbnailKeys', array_values(array_unique($thumbnailKeys)))
            ->setMaxResults(count($thumbnailKeys));
        return $repoClass->find($queryBuilder, false) ?? new DesignStudioThumbnails();
    }

    /**
     * @param DesignDirectoryBoards $designDirectoryBoards boards of the checkout
     * @param string $themeHash
     * @param string[] $priorityBoardKeys boards listed first (the ones on screen)
     * @return DesignDirectoryBoards the boards whose version has no thumbnail in the look, priority boards first
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findBoardsWithoutThumbnail(
        DesignDirectoryBoards $designDirectoryBoards,
        string $themeHash,
        array $priorityBoardKeys = []
    ): DesignDirectoryBoards {
        $designStudioThumbnails = $this->findForBoards($designDirectoryBoards, $themeHash);
        $existingThumbnailKeys = [];
        foreach ($designStudioThumbnails->getElements() as $designStudioThumbnail) {
            $existingThumbnailKeys[(string)$designStudioThumbnail->thumbnailKey] = true;
        }
        $priorityPositions = array_flip(array_values($priorityBoardKeys));
        $priorityBoards = [];
        $otherBoards = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            if (
                $designDirectoryBoard->contentHash === null
                || isset($existingThumbnailKeys[$this->computeThumbnailKey($designDirectoryBoard->contentHash, $themeHash)])
            ) {
                continue;
            }
            if (isset($priorityPositions[$designDirectoryBoard->boardKey])) {
                $priorityBoards[$priorityPositions[$designDirectoryBoard->boardKey]] = $designDirectoryBoard;
            } else {
                $otherBoards[] = $designDirectoryBoard;
            }
        }
        ksort($priorityBoards);
        $boardsWithoutThumbnail = new DesignDirectoryBoards();
        foreach ([...$priorityBoards, ...$otherBoards] as $designDirectoryBoard) {
            $boardsWithoutThumbnail->add($designDirectoryBoard);
        }
        return $boardsWithoutThumbnail;
    }

    /**
     * Keeps the rendered picture of a board version in a look.
     * @param DesignDirectoryBoard $designDirectoryBoard the board version that was rendered
     * @param string $themeHash
     * @param string $png PNG bytes
     * @return DesignStudioThumbnail|null null when no store is configured
     * @throws BadRequestException
     * @throws ImagickException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function storeThumbnail(DesignDirectoryBoard $designDirectoryBoard, string $themeHash, string $png): ?DesignStudioThumbnail
    {
        $thumbnailKey = $this->computeThumbnailKey((string)$designDirectoryBoard->contentHash, $themeHash);
        $existingDesignStudioThumbnail = $this->findByThumbnailKey($thumbnailKey);
        if ($existingDesignStudioThumbnail) {
            return $existingDesignStudioThumbnail;
        }
        $imageSize = getimagesizefromstring($png);
        if (!$imageSize) {
            throw new BadRequestException("Thumbnail of $designDirectoryBoard->boardKey: not an image");
        }
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        $objectKey = $designStudioFilesService->storeThumbnail($thumbnailKey, $png);
        if ($objectKey === null) {
            return null;
        }
        $designStudioThumbnail = new DesignStudioThumbnail();
        $designStudioThumbnail->thumbnailKey = $thumbnailKey;
        $designStudioThumbnail->contentHash = $designDirectoryBoard->contentHash;
        $designStudioThumbnail->themeHash = $themeHash;
        $designStudioThumbnail->rendererVersion = $this->getRendererVersion();
        $designStudioThumbnail->objectKey = $objectKey;
        $designStudioThumbnail->smallObjectKey = $designStudioFilesService->storeSmallThumbnail($thumbnailKey, $png);
        $designStudioThumbnail->width = $imageSize[0];
        $designStudioThumbnail->height = $imageSize[1];
        $designStudioThumbnail->byteSize = strlen($png);
        return $this->update($designStudioThumbnail);
    }

    /**
     * @param string $thumbnailKey
     * @param string $size DesignStudioThumbnail::SIZE_*
     * @return string|null PNG bytes, null when there is no such thumbnail
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getThumbnailContent(string $thumbnailKey, string $size = DesignStudioThumbnail::SIZE_FULL): ?string
    {
        $designStudioThumbnail = preg_match('/^[0-9a-f]{64}$/', $thumbnailKey) ? $this->findByThumbnailKey($thumbnailKey) : null;
        if (!$designStudioThumbnail) {
            return null;
        }
        $objectKey = $size === DesignStudioThumbnail::SIZE_SMALL
            ? ($designStudioThumbnail->smallObjectKey ?? $designStudioThumbnail->objectKey)
            : $designStudioThumbnail->objectKey;
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        return $designStudioFilesService->readThumbnail((string)$objectKey);
    }
}
