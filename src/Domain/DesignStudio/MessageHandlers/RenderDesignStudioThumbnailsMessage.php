<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * One render run of a DesignStudioThumbnailRenderRequest (DesignStudioThumbnailRenderRequestsService::processRequest).
 */
class RenderDesignStudioThumbnailsMessage extends AppMessage
{
    /** @var int|null The DesignStudioThumbnailRenderRequest */
    public ?int $renderRequestId = null;

    /** @var int This message's shard (0 … shardCount - 1) */
    public int $shard = 0;

    /** @var int Shards of the request (the configured concurrency when it was filed) */
    public int $shardCount = 1;

    public static string $messageHandler = RenderDesignStudioThumbnailsHandler::class;

    public function __construct(?int $renderRequestId = null, int $shard = 0, int $shardCount = 1)
    {
        parent::__construct();
        $this->renderRequestId = $renderRequestId;
        $this->shard = $shard;
        $this->shardCount = $shardCount;
    }
}
