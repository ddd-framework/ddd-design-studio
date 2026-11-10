<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DesignStudioThumbnailRenderRequests')]
class DBDesignStudioThumbnailRenderRequestModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DesignStudioThumbnailRenderRequest';

	public const string TABLE_NAME = 'DesignStudioThumbnailRenderRequests';

	public const string ENTITY_CLASS = 'DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest';

	#[ORM\Column(type: 'string')]
	public string $projectKey;

	#[ORM\Column(type: 'string')]
	public string $canvasKey;

	#[ORM\Column(type: 'string')]
	public ?string $themeReference;

	#[ORM\Column(type: 'string')]
	public string $themeHash;

	#[ORM\Column(type: 'string')]
	public string $status;

	#[ORM\Column(type: 'string')]
	public ?string $priorityBoardKeys;

	#[ORM\Column(type: 'integer')]
	public ?int $attempts;

	#[ORM\Column(type: 'string')]
	public ?string $lastError;

	#[ORM\Column(type: 'integer')]
	public ?int $requestedByAccountId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}