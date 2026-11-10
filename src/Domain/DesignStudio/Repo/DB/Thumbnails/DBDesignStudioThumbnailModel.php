<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DesignStudioThumbnails')]
class DBDesignStudioThumbnailModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DesignStudioThumbnail';

	public const string TABLE_NAME = 'DesignStudioThumbnails';

	public const string ENTITY_CLASS = 'DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail';

	#[ORM\Column(type: 'string')]
	public string $thumbnailKey;

	#[ORM\Column(type: 'string')]
	public string $contentHash;

	#[ORM\Column(type: 'string')]
	public string $themeHash;

	#[ORM\Column(type: 'string')]
	public ?string $rendererVersion;

	#[ORM\Column(type: 'string')]
	public string $objectKey;

	#[ORM\Column(type: 'string')]
	public ?string $smallObjectKey;

	#[ORM\Column(type: 'integer')]
	public ?int $width;

	#[ORM\Column(type: 'integer')]
	public ?int $height;

	#[ORM\Column(type: 'integer')]
	public ?int $byteSize;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}