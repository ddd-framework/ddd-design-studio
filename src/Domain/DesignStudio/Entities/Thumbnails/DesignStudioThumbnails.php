<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails;

use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\DBDesignStudioThumbnails;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property DesignStudioThumbnail[] $elements
 * @method DesignStudioThumbnail getByUniqueKey(string $uniqueKey)
 * @method DesignStudioThumbnail[] getElements()
 * @method DesignStudioThumbnail first()
 * @method static DesignStudioThumbnailsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnails::class)]
class DesignStudioThumbnails extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DesignStudioThumbnailsService::class;

    /**
     * @param string $thumbnailKey
     * @return DesignStudioThumbnail|null
     */
    public function getByThumbnailKey(string $thumbnailKey): ?DesignStudioThumbnail
    {
        foreach ($this->getElements() as $designStudioThumbnail) {
            if (($designStudioThumbnail->thumbnailKey ?? null) === $thumbnailKey) {
                return $designStudioThumbnail;
            }
        }
        return null;
    }
}
