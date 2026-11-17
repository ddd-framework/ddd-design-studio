<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Repo\DB\DesignStudioRightsTrait;

/**
 * Database repository for DesignStudioThumbnailRenderPicture entities (rights: DesignStudioRightsTrait)
 *
 * @method DesignStudioThumbnailRenderPicture find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method DesignStudioThumbnailRenderPicture update(DefaultObject &$entity, int $depth = 1)
 * @property DBDesignStudioThumbnailRenderPictureModel $ormInstance
 */
class DBDesignStudioThumbnailRenderPicture extends DBEntity
{
    use DesignStudioRightsTrait;

    public const string BASE_ENTITY_CLASS = DesignStudioThumbnailRenderPicture::class;
    public const string BASE_ORM_MODEL = DBDesignStudioThumbnailRenderPictureModel::class;
}
