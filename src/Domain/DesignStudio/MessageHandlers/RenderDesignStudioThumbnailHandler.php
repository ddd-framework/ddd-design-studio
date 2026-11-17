<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\MessageHandlers;

use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * Renders one picture of a render request. Failures of the picture are handled by the service (retry with delay,
 * then "failed with reason"), never thrown.
 *
 * Carries no AsMessageHandler attribute on purpose: the application registers it in its services.yaml with the
 * `messenger.message_handler` tag (`from_transport: render_design_studio_thumbnails`), the one place that binds it.
 */
class RenderDesignStudioThumbnailHandler extends AppMessageHandler
{
    public function __invoke(RenderDesignStudioThumbnailMessage $renderDesignStudioThumbnailMessage): void
    {
        set_time_limit(DesignStudioThumbnailRenderRequestsService::PICTURE_TIME_LIMIT_SECONDS);
        ini_set('memory_limit', '512M');
        $this->setAuthAccountFromMessage($renderDesignStudioThumbnailMessage);
        /** @var DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService */
        $designStudioThumbnailRenderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
        try {
            $designStudioThumbnailRenderRequestsService->processPicture(
                (int)$renderDesignStudioThumbnailMessage->renderRequestId,
                (string)$renderDesignStudioThumbnailMessage->renderTargetKey,
                $renderDesignStudioThumbnailMessage->attempt
            );
        } catch (Throwable $throwable) {
            $this->getLogger()->error(
                "Render design studio thumbnail $renderDesignStudioThumbnailMessage->renderTargetKey of request "
                . "$renderDesignStudioThumbnailMessage->renderRequestId failed: " . $throwable->getMessage()
            );
            throw $throwable;
        }
    }
}
