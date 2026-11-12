<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Comments;

use DDD\Domain\DesignStudio\Repo\DB\Comments\DBDesignStudioComments;
use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * @property DesignStudioComment[] $elements
 * @method DesignStudioComment getByUniqueKey(string $uniqueKey)
 * @method DesignStudioComment[] getElements()
 * @method DesignStudioComment first()
 * @method static DesignStudioCommentsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioComments::class)]
class DesignStudioComments extends EntitySet
{
    public const string SERVICE_NAME = DesignStudioCommentsService::class;
}
