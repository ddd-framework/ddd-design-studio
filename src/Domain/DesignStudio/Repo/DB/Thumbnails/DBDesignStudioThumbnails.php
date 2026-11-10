<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails;

use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DesignStudioThumbnails find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDesignStudioThumbnails extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBDesignStudioThumbnail::class;
    public const BASE_ENTITY_SET_CLASS = DesignStudioThumbnails::class;
}
