<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests;

use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequests;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DesignStudioThumbnailRenderRequests find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDesignStudioThumbnailRenderRequests extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBDesignStudioThumbnailRenderRequest::class;
    public const BASE_ENTITY_SET_CLASS = DesignStudioThumbnailRenderRequests::class;
}
