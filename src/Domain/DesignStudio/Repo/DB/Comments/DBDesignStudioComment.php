<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Comments;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Domain\DesignStudio\Repo\DB\DesignStudioRightsTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for DesignStudioComment entities (rights: DesignStudioRightsTrait)
 *
 * @method DesignStudioComment find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DesignStudioComment update(DefaultObject &$entity, int $depth = 1)
 * @property DBDesignStudioCommentModel $ormInstance
 */
class DBDesignStudioComment extends DBEntity
{
    use DesignStudioRightsTrait;

    public const string BASE_ENTITY_CLASS = DesignStudioComment::class;
    public const string BASE_ORM_MODEL = DBDesignStudioCommentModel::class;
}
