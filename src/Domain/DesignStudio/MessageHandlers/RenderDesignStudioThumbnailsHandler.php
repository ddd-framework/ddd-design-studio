<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Renders the missing thumbnails of a canvas through the renderer service. A run ends after the service's run budget
 * and continues in a new message; renderer outages are retried with backoff by the service, never thrown.
 */
#[AsMessageHandler(fromTransport: 'render_design_studio_thumbnails')]
class RenderDesignStudioThumbnailsHandler extends AppMessageHandler
{
    public function __invoke(RenderDesignStudioThumbnailsMessage $renderDesignStudioThumbnailsMessage): void
    {
        set_time_limit(DesignStudioThumbnailRenderRequestsService::RUN_BUDGET_SECONDS + 180);
        ini_set('memory_limit', '512M');
        $this->setAuthAccountFromMessage($renderDesignStudioThumbnailsMessage);
        $this->getLogger()->info("Render design studio thumbnails for request $renderDesignStudioThumbnailsMessage->renderRequestId, shard "
            . "$renderDesignStudioThumbnailsMessage->shard/$renderDesignStudioThumbnailsMessage->shardCount");
        /** @var DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService */
        $designStudioThumbnailRenderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
        $designStudioThumbnailRenderRequestsService->processRequest(
            (int)$renderDesignStudioThumbnailsMessage->renderRequestId,
            $renderDesignStudioThumbnailsMessage->shard,
            $renderDesignStudioThumbnailsMessage->shardCount
        );
    }
}
