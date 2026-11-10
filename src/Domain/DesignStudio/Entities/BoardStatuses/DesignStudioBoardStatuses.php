<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\BoardStatuses;

use DDD\Domain\DesignStudio\Repo\DB\BoardStatuses\DBDesignStudioBoardStatuses;
use DDD\Domain\DesignStudio\Services\DesignStudioBoardStatusesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property DesignStudioBoardStatus[] $elements
 * @method DesignStudioBoardStatus getByUniqueKey(string $uniqueKey)
 * @method DesignStudioBoardStatus[] getElements()
 * @method DesignStudioBoardStatus first()
 * @method static DesignStudioBoardStatusesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioBoardStatuses::class)]
class DesignStudioBoardStatuses extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = DesignStudioBoardStatusesService::class;
}
