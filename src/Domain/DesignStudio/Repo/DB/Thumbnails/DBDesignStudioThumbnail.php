<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails;

use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Repo\DB\DesignStudioRightsTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for DesignStudioThumbnail entities (rights: DesignStudioRightsTrait)
 *
 * @method DesignStudioThumbnail find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DesignStudioThumbnail update(DefaultObject &$entity, int $depth = 1)
 * @property DBDesignStudioThumbnailModel $ormInstance
 */
class DBDesignStudioThumbnail extends DBEntity
{
    use DesignStudioRightsTrait;

    public const string BASE_ENTITY_CLASS = DesignStudioThumbnail::class;
    public const string BASE_ORM_MODEL = DBDesignStudioThumbnailModel::class;
}
