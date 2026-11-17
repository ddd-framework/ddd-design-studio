<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * @deprecated since 0.9.0, removed in the next minor release: one shard run of a render request of 0.8 or earlier.
 * Only handled to convert messages still queued at deploy time (RenderDesignStudioThumbnailsHandler); nothing
 * dispatches it any more. Pictures are rendered by RenderDesignStudioThumbnailMessage.
 */
class RenderDesignStudioThumbnailsMessage extends AppMessage
{
    /** @var int|null The DesignStudioThumbnailRenderRequest */
    public ?int $renderRequestId = null;

    /** @var int This message's shard (0 … shardCount - 1) */
    public int $shard = 0;

    /** @var int Shards of the request (the configured concurrency when it was filed) */
    public int $shardCount = 1;

    /** @var int Follow-up runs in a row for boards that changed during a run */
    public int $changedBoardFollowUpCount = 0;

    /** @var string|null The missing boards a watching run saw last; null = no watching run */
    public ?string $remainingBoardsFingerprint = null;

    /** @var int|null Since when (Unix time) the missing boards are the same */
    public ?int $remainingBoardsUnchangedSince = null;

    public static string $messageHandler = RenderDesignStudioThumbnailsHandler::class;

    public function __construct(
        ?int $renderRequestId = null,
        int $shard = 0,
        int $shardCount = 1,
        int $changedBoardFollowUpCount = 0,
        ?string $remainingBoardsFingerprint = null,
        ?int $remainingBoardsUnchangedSince = null
    ) {
        parent::__construct();
        $this->renderRequestId = $renderRequestId;
        $this->shard = $shard;
        $this->shardCount = $shardCount;
        $this->changedBoardFollowUpCount = $changedBoardFollowUpCount;
        $this->remainingBoardsFingerprint = $remainingBoardsFingerprint;
        $this->remainingBoardsUnchangedSince = $remainingBoardsUnchangedSince;
    }
}
