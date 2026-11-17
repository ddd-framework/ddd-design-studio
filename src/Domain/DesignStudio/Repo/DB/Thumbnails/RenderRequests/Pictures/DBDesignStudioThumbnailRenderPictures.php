<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictures;

/**
 * @method DesignStudioThumbnailRenderPictures find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDesignStudioThumbnailRenderPictures extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBDesignStudioThumbnailRenderPicture::class;
    public const BASE_ENTITY_SET_CLASS = DesignStudioThumbnailRenderPictures::class;
}
