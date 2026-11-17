<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\Pictures\DBDesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderPicturesService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One picture of a render request (a fixed board, or one device frame of a responsive board): rendered by a message of
 * its own (RenderDesignStudioThumbnailMessage). Its status moves from QUEUED to DONE, FAILED or SKIPPED exactly once;
 * the request closes when no picture is QUEUED any more.
 *
 * @method static DesignStudioThumbnailRenderPicturesService getService()
 * @method static DBDesignStudioThumbnailRenderPicture getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioThumbnailRenderPicture::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['renderRequestId', 'renderTargetKey'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['renderRequestId', 'status'])]
class DesignStudioThumbnailRenderPicture extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Dispatched, not finished (a retry with delay keeps it here) */
    public const string STATUS_QUEUED = 'QUEUED';

    /** @var string Its thumbnail is stored */
    public const string STATUS_DONE = 'DONE';

    /** @var string Given up: refused by the renderer, or failed on every try; lastError says why */
    public const string STATUS_FAILED = 'FAILED';

    /** @var string Not rendered by this request: the board is gone, or its thumbnail was there already */
    public const string STATUS_SKIPPED = 'SKIPPED';

    /** @var string[] Statuses a picture never leaves */
    public const array FINISHED_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_SKIPPED];

    /** @var int|null The request the picture belongs to */
    #[NotNull]
    public ?int $renderRequestId;

    #[LazyLoad(addAsParent: true)]
    public ?DesignStudioThumbnailRenderRequest $renderRequest;

    /** @var string|null DesignDirectoryBoard::getRenderTargetKey(): the board key, `<boardKey>@<deviceKey>` for a frame */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $renderTargetKey;

    /** @var string|null Key of the board (file name) */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $boardKey;

    /** @var string|null Device of the frame of a responsive board, null for a fixed board */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $frameDeviceKey;

    /** @var int|null Dispatch order within the request (boards on screen first) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $position;

    /** @var string|null STATUS_* */
    #[NotNull]
    #[Length(max: 16)]
    #[Choice(choices: [self::STATUS_QUEUED, self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_SKIPPED])]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $status;

    /** @var int|null Tries so far (a retry is dispatched with a delay until MAX_PICTURE_ATTEMPTS) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $attempts;

    /** @var string|null Why the last try failed (kept when the picture failed for good) */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $lastError;

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignDirectoryBoard $renderTarget a fixed board or a device frame of a responsive board
     * @param int $position
     * @return static a QUEUED picture (not stored)
     */
    public static function forRenderTarget(DesignStudioThumbnailRenderRequest $renderRequest, DesignDirectoryBoard $renderTarget, int $position): static
    {
        $designStudioThumbnailRenderPicture = new static();
        $designStudioThumbnailRenderPicture->renderRequestId = (int)$renderRequest->id;
        $designStudioThumbnailRenderPicture->renderTargetKey = $renderTarget->getRenderTargetKey();
        $designStudioThumbnailRenderPicture->boardKey = (string)$renderTarget->boardKey;
        $designStudioThumbnailRenderPicture->frameDeviceKey = $renderTarget->frameDeviceKey;
        $designStudioThumbnailRenderPicture->position = $position;
        $designStudioThumbnailRenderPicture->status = static::STATUS_QUEUED;
        $designStudioThumbnailRenderPicture->attempts = 0;
        $designStudioThumbnailRenderPicture->lastError = null;
        return $designStudioThumbnailRenderPicture;
    }

    /**
     * @return bool the picture has reached its final status
     */
    public function isFinished(): bool
    {
        return in_array($this->status ?? null, static::FINISHED_STATUSES, true);
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
