<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Comments;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComments;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method DesignStudioComments find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBDesignStudioComments extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBDesignStudioComment::class;
    public const BASE_ENTITY_SET_CLASS = DesignStudioComments::class;
}
