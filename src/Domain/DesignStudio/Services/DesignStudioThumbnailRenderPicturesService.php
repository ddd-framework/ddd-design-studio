<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictureCounts;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictures;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures\DBDesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures\DBDesignStudioThumbnailRenderPictures;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The pictures of render requests: one row per picture, written by the request when it is filed and moved to its
 * final status exactly once (compare-and-set) by the message that renders it.
 *
 * @method DesignStudioThumbnailRenderPicture find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioThumbnailRenderPictures findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioThumbnailRenderPicture getEntityRepoClassInstance()
 * @method DBDesignStudioThumbnailRenderPictures getEntitySetRepoClassInstance()
 */
class DesignStudioThumbnailRenderPicturesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioThumbnailRenderPicture::class;

    /**
     * Writes the pictures of a request; a picture that is there already (same request and render target) is kept as
     * it is.
     * @param DesignStudioThumbnailRenderPictures $designStudioThumbnailRenderPictures
     * @return void
     */
    public function createPictures(DesignStudioThumbnailRenderPictures $designStudioThumbnailRenderPictures): void
    {
        if (!$designStudioThumbnailRenderPictures->count()) {
            return;
        }
        $designStudioThumbnailRenderPictures->batchUpdate(true);
    }

    /**
     * @param int $renderRequestId
     * @param string $renderTargetKey
     * @return DesignStudioThumbnailRenderPicture|null the picture as stored now
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findPicture(int $renderRequestId, string $renderTargetKey): ?DesignStudioThumbnailRenderPicture
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.renderRequestId = :renderRequestId")
            ->andWhere("$alias.renderTargetKey = :renderTargetKey")
            ->setParameter('renderRequestId', $renderRequestId)
            ->setParameter('renderTargetKey', $renderTargetKey)
            ->setMaxResults(1);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return DesignStudioThumbnailRenderPictures the pictures not finished yet, in dispatch order
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findQueuedPictures(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderPictures
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.renderRequestId = :renderRequestId")
            ->andWhere("$alias.status = :status")
            ->setParameter('renderRequestId', $renderRequest->id)
            ->setParameter('status', DesignStudioThumbnailRenderPicture::STATUS_QUEUED)
            ->orderBy("$alias.position", 'ASC');
        return $repoClass->find($queryBuilder, false) ?? new DesignStudioThumbnailRenderPictures();
    }

    /**
     * Moves a QUEUED picture to its final status; exactly one caller wins (a redelivered or duplicate message loses).
     * @param DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture
     * @param string $status DesignStudioThumbnailRenderPicture::STATUS_DONE / _FAILED / _SKIPPED
     * @param string|null $lastError
     * @return bool true for the caller that moved it
     */
    public function finishPicture(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture, string $status, ?string $lastError): bool
    {
        $lastError = $lastError !== null ? mb_substr($lastError, 0, 1000) : null;
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $affectedRows = (int)$repoClass::createQueryBuilder()
            ->update($repoClass::BASE_ORM_MODEL, $alias)
            ->set("$alias.status", ':finishedStatus')
            ->set("$alias.lastError", ':lastError')
            ->where("$alias.id = :renderPictureId")
            ->andWhere("$alias.status = :queuedStatus")
            ->setParameter('finishedStatus', $status)
            ->setParameter('lastError', $lastError)
            ->setParameter('renderPictureId', $designStudioThumbnailRenderPicture->id)
            ->setParameter('queuedStatus', DesignStudioThumbnailRenderPicture::STATUS_QUEUED)
            ->getQuery()
            ->execute();
        if ($affectedRows !== 1) {
            return false;
        }
        $designStudioThumbnailRenderPicture->status = $status;
        $designStudioThumbnailRenderPicture->lastError = $lastError;
        return true;
    }

    /**
     * Keeps the number of tries and the reason of the last failed one (the picture stays QUEUED for its retry).
     * @param DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture
     * @param int $attempts
     * @param string $lastError
     * @return void
     */
    public function recordFailedTry(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture, int $attempts, string $lastError): void
    {
        $designStudioThumbnailRenderPicture->attempts = $attempts;
        $designStudioThumbnailRenderPicture->lastError = mb_substr($lastError, 0, 1000);
        $designStudioThumbnailRenderPicture->updatePartialIgnoringRights('attempts', 'lastError');
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return DesignStudioThumbnailRenderPictureCounts the request's pictures by status, as stored now
     */
    public function findPictureCounts(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderPictureCounts
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $queryBuilder->select("$alias.status AS pictureStatus", "COUNT($alias.id) AS pictureCount")
            ->where("$alias.renderRequestId = :renderRequestId")
            ->setParameter('renderRequestId', $renderRequest->id)
            ->groupBy("$alias.status");
        $designStudioThumbnailRenderPictureCounts = new DesignStudioThumbnailRenderPictureCounts();
        foreach ($queryBuilder->getQuery()->getScalarResult() as $row) {
            $pictureCount = (int)$row['pictureCount'];
            match ((string)$row['pictureStatus']) {
                DesignStudioThumbnailRenderPicture::STATUS_DONE => $designStudioThumbnailRenderPictureCounts->doneCount = $pictureCount,
                DesignStudioThumbnailRenderPicture::STATUS_FAILED => $designStudioThumbnailRenderPictureCounts->failedCount = $pictureCount,
                DesignStudioThumbnailRenderPicture::STATUS_SKIPPED => $designStudioThumbnailRenderPictureCounts->skippedCount = $pictureCount,
                default => $designStudioThumbnailRenderPictureCounts->queuedCount += $pictureCount,
            };
        }
        return $designStudioThumbnailRenderPictureCounts;
    }
}
