<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\BoardStatuses;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DesignStudioBoardStatuses')]
class DBDesignStudioBoardStatusModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DesignStudioBoardStatus';

	public const string TABLE_NAME = 'DesignStudioBoardStatuses';

	public const string ENTITY_CLASS = 'DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatus';

	#[ORM\Column(type: 'string')]
	public string $projectKey;

	#[ORM\Column(type: 'string')]
	public string $canvasKey;

	#[ORM\Column(type: 'string')]
	public string $boardKey;

	#[ORM\Column(type: 'string')]
	public string $status;

	#[ORM\Column(type: 'string')]
	public ?string $contentHash;

	#[ORM\Column(type: 'integer')]
	public ?int $changedByAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $changedByName;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}