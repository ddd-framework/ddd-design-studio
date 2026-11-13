<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB\Comments;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'DesignStudioComments')]
class DBDesignStudioCommentModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'DesignStudioComment';

	public const string TABLE_NAME = 'DesignStudioComments';

	public const string ENTITY_CLASS = 'DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment';

	#[ORM\Column(type: 'string')]
	public string $projectKey;

	#[ORM\Column(type: 'string')]
	public string $canvasKey;

	#[ORM\Column(type: 'string')]
	public string $boardKey;

	#[ORM\Column(type: 'string')]
	public string $contentHash;

	#[ORM\Column(type: 'integer')]
	public ?int $parentDesignStudioCommentId;

	#[ORM\Column(type: 'float')]
	public ?float $x;

	#[ORM\Column(type: 'float')]
	public ?float $y;

	#[ORM\Column(type: 'integer')]
	public ?int $viewportWidth;

	#[ORM\Column(type: 'integer')]
	public ?int $viewportHeight;

	#[ORM\Column(type: 'string')]
	public ?string $deviceKey;

	#[ORM\Column(type: 'string')]
	public string $text;

	#[ORM\Column(type: 'integer')]
	public ?int $authorAccountId;

	#[ORM\Column(type: 'string')]
	public ?string $authorName;

	#[ORM\Column(type: 'string')]
	public string $authorKind;

	#[ORM\Column(type: 'boolean')]
	public bool $isResolved;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $resolvedAt;

	#[ORM\Column(type: 'string')]
	public ?string $resolvedByName;

	#[ORM\Column(type: 'boolean')]
	public bool $isDecision;

	#[ORM\Column(type: 'string')]
	public ?string $snapshotObjectKey;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastActivityAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBDesignStudioCommentModel::class)]
	#[ORM\JoinColumn(name: 'parentDesignStudioCommentId', referencedColumnName: 'id')]
	public ?DBDesignStudioCommentModel $parentDesignStudioComment;

}