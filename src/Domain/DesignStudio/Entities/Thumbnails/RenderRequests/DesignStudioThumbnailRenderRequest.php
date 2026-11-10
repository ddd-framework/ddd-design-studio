<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests;

use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The order to render the missing thumbnails of a canvas in one look. Filed when someone looks at a canvas whose
 * thumbnails are incomplete; worked off by the render worker in runs (DesignStudioThumbnailRenderRequestsService).
 * At most one open request per canvas and theme hash.
 *
 * @method static DesignStudioThumbnailRenderRequestsService getService()
 * @method static DBDesignStudioThumbnailRenderRequest getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnailRenderRequest::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['projectKey', 'canvasKey', 'themeHash'])]
class DesignStudioThumbnailRenderRequest extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Filed, no run has started yet */
    public const string STATUS_QUEUED = 'QUEUED';

    /** @var string A run is rendering */
    public const string STATUS_RUNNING = 'RUNNING';

    /** @var string The renderer is busy or unreachable; a retry is scheduled */
    public const string STATUS_WAITING_FOR_RENDERER = 'WAITING_FOR_RENDERER';

    /** @var string Nothing is missing any more */
    public const string STATUS_DONE = 'DONE';

    /** @var string Given up after too many waiting runs */
    public const string STATUS_FAILED = 'FAILED';

    /** @var string[] Statuses of a request that still has work to do */
    public const array OPEN_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_WAITING_FOR_RENDERER];

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

    /** @var string|null Theme reference `<themeId>[:<mode>]` the thumbnails are rendered in; null = no theme */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $themeReference;

    /** @var string|null SHA-256 of the theme's `<style>` element when the request was filed */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $themeHash;

    /** @var string|null STATUS_* */
    #[NotNull]
    #[Length(max: 32)]
    public ?string $status;

    /** @var string|null Board keys rendered first (the boards on screen), one per line */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $priorityBoardKeys;

    /** @var int|null Waiting runs in a row */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $attempts;

    /** @var string|null Last renderer problem */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $lastError;

    /** @var int|null Id of the account that looked at the canvas; no relation, the account may be gone */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $requestedByAccountId;

    /**
     * @return bool the request still has work to do
     */
    public function isOpen(): bool
    {
        return in_array($this->status ?? null, static::OPEN_STATUSES, true);
    }

    /**
     * @return string[] the board keys rendered first
     */
    public function getPriorityBoardKeyList(): array
    {
        $priorityBoardKeys = trim((string)($this->priorityBoardKeys ?? ''));
        return $priorityBoardKeys === '' ? [] : explode("\n", $priorityBoardKeys);
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
