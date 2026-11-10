<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\BoardStatuses;

use DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatuses;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DesignStudioBoardStatuses find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDesignStudioBoardStatuses extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBDesignStudioBoardStatus::class;
    public const BASE_ENTITY_SET_CLASS = DesignStudioBoardStatuses::class;
}
