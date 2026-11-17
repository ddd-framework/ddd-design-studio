<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Services\DDDService;

/**
 * @deprecated since 0.9.0, removed in the next minor release: a shard run of a request filed by 0.8 or earlier that
 * was still queued at deploy time. It is converted into one RenderDesignStudioThumbnailMessage per missing picture of
 * the request (once per request; the other shards' messages find the pictures there and end).
 *
 * Carries no AsMessageHandler attribute on purpose: the application registers it in its services.yaml with the
 * `messenger.message_handler` tag (`from_transport: render_design_studio_thumbnails`).
 */
class RenderDesignStudioThumbnailsHandler extends AppMessageHandler
{
    public function __invoke(RenderDesignStudioThumbnailsMessage $renderDesignStudioThumbnailsMessage): void
    {
        ini_set('memory_limit', '512M');
        $this->setAuthAccountFromMessage($renderDesignStudioThumbnailsMessage);
        /** @var DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService */
        $designStudioThumbnailRenderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
        $designStudioThumbnailRenderRequestsService->convertShardRunToPictureMessages((int)$renderDesignStudioThumbnailsMessage->renderRequestId);
    }
}
