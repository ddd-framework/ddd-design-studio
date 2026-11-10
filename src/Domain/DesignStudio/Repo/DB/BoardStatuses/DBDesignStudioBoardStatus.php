<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\BoardStatuses;

use DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatus;
use DDD\Domain\DesignStudio\Repo\DB\DesignStudioRightsTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for DesignStudioBoardStatus entities (rights: DesignStudioRightsTrait)
 *
 * @method DesignStudioBoardStatus find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DesignStudioBoardStatus update(DefaultObject &$entity, int $depth = 1)
 * @property DBDesignStudioBoardStatusModel $ormInstance
 */
class DBDesignStudioBoardStatus extends DBEntity
{
    use DesignStudioRightsTrait;

    public const string BASE_ENTITY_CLASS = DesignStudioBoardStatus::class;
    public const string BASE_ORM_MODEL = DBDesignStudioBoardStatusModel::class;
}
