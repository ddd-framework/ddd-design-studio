<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures\DBDesignStudioThumbnailRenderPictures;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderPicturesService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * @property DesignStudioThumbnailRenderPicture[] $elements
 * @method DesignStudioThumbnailRenderPicture getByUniqueKey(string $uniqueKey)
 * @method DesignStudioThumbnailRenderPicture[] getElements()
 * @method DesignStudioThumbnailRenderPicture first()
 * @method static DesignStudioThumbnailRenderPicturesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnailRenderPictures::class)]
class DesignStudioThumbnailRenderPictures extends EntitySet
{
    public const string SERVICE_NAME = DesignStudioThumbnailRenderPicturesService::class;

    /**
     * @param int $currentTimestamp
     * @return static the pictures whose next try (a retry with delay) is still ahead
     */
    public function getPicturesWaitingForRetry(int $currentTimestamp): static
    {
        $picturesWaitingForRetry = new static();
        foreach ($this->getElements() as $designStudioThumbnailRenderPicture) {
            if ($designStudioThumbnailRenderPicture->isWaitingForRetry($currentTimestamp)) {
                $picturesWaitingForRetry->add($designStudioThumbnailRenderPicture);
            }
        }
        return $picturesWaitingForRetry;
    }

    /**
     * @return DateTime|null the latest next try of these pictures, null when none has one
     */
    public function getLatestNextAttemptAt(): ?DateTime
    {
        $latestNextAttemptAt = null;
        foreach ($this->getElements() as $designStudioThumbnailRenderPicture) {
            $nextAttemptAt = $designStudioThumbnailRenderPicture->nextAttemptAt ?? null;
            if ($nextAttemptAt !== null && ($latestNextAttemptAt === null || $nextAttemptAt->getTimestamp() > $latestNextAttemptAt->getTimestamp())) {
                $latestNextAttemptAt = $nextAttemptAt;
            }
        }
        return $latestNextAttemptAt;
    }
}
