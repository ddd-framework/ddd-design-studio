<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\BoardStatuses;

use DDD\Domain\DesignStudio\Repo\DB\BoardStatuses\DBDesignStudioBoardStatus;
use DDD\Domain\DesignStudio\Services\DesignStudioBoardStatusesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The review status of one design board (one row per board; a board without a row has the default status). Boards
 * are files of the checkout, so the row names its board by keys. `contentHash` is the board version the status was
 * set on: when the file has another hash today, the studio hints that the board changed since. Status keys are
 * configured in `DesignStudio.statuses`.
 *
 * @method static DesignStudioBoardStatusesService getService()
 * @method static DBDesignStudioBoardStatus getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioBoardStatus::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['projectKey', 'canvasKey', 'boardKey'])]
class DesignStudioBoardStatus extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string|null Key of the design project (config DesignStudio.projects) */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $projectKey;

    /** @var string|null Key of the canvas within the project */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $canvasKey;

    /** @var string|null File name of the board in the canvas folder */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $boardKey;

    /** @var string|null Status key (config DesignStudio.statuses) */
    #[NotNull]
    #[Length(max: 32)]
    public ?string $status;

    /** @var string|null Content hash of the board file when the status was set (git blob hash) */
    #[Length(max: 40)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentHash;

    /** @var int|null Id of the account that set the status; no relation, the account may be gone */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $changedByAccountId;

    /** @var string|null Who set the status, as shown in the studio */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $changedByName;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
