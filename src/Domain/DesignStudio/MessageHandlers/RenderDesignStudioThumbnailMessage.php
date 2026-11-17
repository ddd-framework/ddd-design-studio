<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Renders one picture of a DesignStudioThumbnailRenderRequest (DesignStudioThumbnailRenderRequestsService::processPicture()):
 * a fixed board, or one device frame of a responsive board.
 */
class RenderDesignStudioThumbnailMessage extends AppMessage
{
    /** @var int|null The DesignStudioThumbnailRenderRequest */
    public ?int $renderRequestId = null;

    /** @var string|null The picture: DesignDirectoryBoard::getRenderTargetKey() */
    public ?string $renderTargetKey = null;

    /** @var int This try of the picture (1 = first; a retry is dispatched with a delay and the next number) */
    public int $attempt = 1;

    public static string $messageHandler = RenderDesignStudioThumbnailHandler::class;

    public function __construct(?int $renderRequestId = null, ?string $renderTargetKey = null, int $attempt = 1)
    {
        parent::__construct();
        $this->renderRequestId = $renderRequestId;
        $this->renderTargetKey = $renderTargetKey;
        $this->attempt = $attempt;
    }
}
