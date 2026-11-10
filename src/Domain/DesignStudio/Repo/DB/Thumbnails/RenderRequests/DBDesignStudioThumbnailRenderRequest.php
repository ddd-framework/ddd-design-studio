<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests;

use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\DB\DesignStudioRightsTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for DesignStudioThumbnailRenderRequest entities (rights: DesignStudioRightsTrait)
 *
 * @method DesignStudioThumbnailRenderRequest find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DesignStudioThumbnailRenderRequest update(DefaultObject &$entity, int $depth = 1)
 * @property DBDesignStudioThumbnailRenderRequestModel $ormInstance
 */
class DBDesignStudioThumbnailRenderRequest extends DBEntity
{
    use DesignStudioRightsTrait;

    public const string BASE_ENTITY_CLASS = DesignStudioThumbnailRenderRequest::class;
    public const string BASE_ORM_MODEL = DBDesignStudioThumbnailRenderRequestModel::class;
}
