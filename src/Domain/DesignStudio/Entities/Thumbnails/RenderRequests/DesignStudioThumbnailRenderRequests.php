<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests;

use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequests;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property DesignStudioThumbnailRenderRequest[] $elements
 * @method DesignStudioThumbnailRenderRequest getByUniqueKey(string $uniqueKey)
 * @method DesignStudioThumbnailRenderRequest[] getElements()
 * @method DesignStudioThumbnailRenderRequest first()
 * @method static DesignStudioThumbnailRenderRequestsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnailRenderRequests::class)]
class DesignStudioThumbnailRenderRequests extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DesignStudioThumbnailRenderRequestsService::class;
}
