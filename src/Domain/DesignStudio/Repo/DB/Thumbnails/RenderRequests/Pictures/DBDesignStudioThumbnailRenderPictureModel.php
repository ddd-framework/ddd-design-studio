<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequestModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DesignStudioThumbnailRenderPictures')]
class DBDesignStudioThumbnailRenderPictureModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DesignStudioThumbnailRenderPicture';

	public const string TABLE_NAME = 'DesignStudioThumbnailRenderPictures';

	public const string ENTITY_CLASS = 'DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture';

	#[ORM\Column(type: 'integer')]
	public int $renderRequestId;

	#[ORM\Column(type: 'string')]
	public string $renderTargetKey;

	#[ORM\Column(type: 'string')]
	public string $boardKey;

	#[ORM\Column(type: 'string')]
	public ?string $frameDeviceKey;

	#[ORM\Column(type: 'integer')]
	public ?int $position;

	#[ORM\Column(type: 'string')]
	public string $status;

	#[ORM\Column(type: 'integer')]
	public ?int $attempts;

	#[ORM\Column(type: 'string')]
	public ?string $lastError;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBDesignStudioThumbnailRenderRequestModel::class)]
	#[ORM\JoinColumn(name: 'renderRequestId', referencedColumnName: 'id')]
	public DBDesignStudioThumbnailRenderRequestModel $renderRequest;

}