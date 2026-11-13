<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Comments;

use DDD\Domain\DesignStudio\Repo\DB\Comments\DBDesignStudioComment;
use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A comment on a design board. Boards are files of the checkout and never database rows, so a comment names its board
 * by keys (project, canvas, board file) and the version it was written on by the board's content hash. A board whose
 * file has another hash today "changed since this comment". Optionally pinned at a point of the board (x / y in board
 * pixels) and optionally a reply in a thread (parentDesignStudioCommentId = the thread's root). `isDecision` marks a
 * decision for the decisions log. A thread without activity expires (DesignStudio.retention); `lastActivityAt` on the
 * root is the clock.
 *
 * @method static DesignStudioCommentsService getService()
 * @method static DBDesignStudioComment getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBDesignStudioComment::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['projectKey', 'canvasKey', 'boardKey'])]
class DesignStudioComment extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Written by a person in the studio */
    public const string AUTHOR_KIND_USER = 'USER';

    /** @var string Written by an agent through the console commands */
    public const string AUTHOR_KIND_AGENT = 'AGENT';

    /** @var int Maximum length of a comment text */
    public const int MAX_TEXT_LENGTH = 10000;

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

    /** @var string|null Content hash of the board file the comment was written on (git blob hash) */
    #[NotNull]
    #[Length(max: 40)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $contentHash;

    /** @var int|null The thread root this comment replies to; null = a thread root */
    public ?int $parentDesignStudioCommentId;

    /** @var DesignStudioComment|null Deleting a thread root deletes its replies */
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?DesignStudioComment $parentDesignStudioComment;

    /** @var float|null Pin position from the board's left edge in board pixels; null = a comment on the whole board */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $x;

    /** @var float|null Pin position from the board's top edge in board pixels */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $y;

    /**
     * @var int|null Viewport width in CSS pixels the comment was placed in (a responsive board: x / y are positions in
     *      the page at this width); null = a fixed board, x / y are board pixels
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $viewportWidth;

    /** @var int|null Viewport height in CSS pixels the comment was placed in; null = a fixed board */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $viewportHeight;

    /** @var string|null Device preset the comment was placed in (DesignDevice::$deviceKey); null = a free width or a fixed board */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $deviceKey;

    /** @var string|null The comment */
    #[NotNull]
    #[Length(max: self::MAX_TEXT_LENGTH)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $text;

    /** @var int|null Id of the account that wrote it (the console account for agent comments); no relation, the account may be gone */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $authorAccountId;

    /** @var string|null Author as shown in the studio */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $authorName;

    /** @var string|null USER or AGENT */
    #[NotNull]
    #[Choice(choices: [self::AUTHOR_KIND_USER, self::AUTHOR_KIND_AGENT])]
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $authorKind;

    /** @var bool|null Resolved (thread roots only) */
    #[NotNull]
    public ?bool $isResolved;

    /** @var DateTime|null When it was resolved */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $resolvedAt;

    /** @var string|null Who resolved it */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $resolvedByName;

    /** @var bool|null Marks a decision (decisions log); decisions do not expire unless configured */
    #[NotNull]
    public ?bool $isDecision;

    /** @var string|null Object key of the board file as it was when the thread started (thread roots only), so the commented version stays viewable */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $snapshotObjectKey;

    /** @var DateTime|null Last activity in the thread (comment, reply, resolve, reopen, keep); set on the thread root, drives expiry */
    public ?DateTime $lastActivityAt;

    /**
     * @return bool Whether this comment starts a thread (has no parent)
     */
    public function isThreadRoot(): bool
    {
        return ($this->parentDesignStudioCommentId ?? null) === null;
    }

    /**
     * @return DesignStudioCommentViewport|null the viewport the pin was placed in, null on a fixed board
     */
    public function getViewport(): ?DesignStudioCommentViewport
    {
        return DesignStudioCommentViewport::fromWidthHeightAndDeviceKey($this->viewportWidth ?? null, $this->viewportHeight ?? null, $this->deviceKey ?? null);
    }

    /**
     * @param string|null $currentContentHash content hash of the board file in the checkout, null when the board is not there
     * @return bool true when the board is still there and its content differs from the version the comment was written on
     */
    public function hasBoardChangedSince(?string $currentContentHash): bool
    {
        return $currentContentHash !== null && $currentContentHash !== ($this->contentHash ?? null);
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
