<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures\DBDesignStudioThumbnailRenderPictures;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderPicturesService;

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
}
