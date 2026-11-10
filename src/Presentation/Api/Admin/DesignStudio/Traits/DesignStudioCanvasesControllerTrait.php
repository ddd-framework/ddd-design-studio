<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Traits;

use DDD\Domain\DesignStudio\Services\DesignStudioBoardStatusesService;
use DDD\Domain\DesignStudio\Services\DesignStudioCanvasViewsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioBoardRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioBoardStatusGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioBoardStatusPatchRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCanvasGetRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCanvasGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCanvasRevisionGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioOverviewGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioThemesGetRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioThemesGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioThumbnailRenderRequestPostRequestDto;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Patch;
use DDD\Presentation\Base\Router\Routes\Post;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Design studio routes for the overview, canvases, board statuses and themes. An application uses the trait in a
 * controller of its own (base controller, route prefix, tag and logging are the application's).
 */
trait DesignStudioCanvasesControllerTrait
{
    use DesignStudioViewerTrait;

    /**
     * The configured projects with their canvases (board counts, open threads, decisions) and what the studio is
     * configured with.
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @return DesignStudioOverviewGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Get('/overview')]
    #[Summary('DesignStudio Overview')]
    public function getOverview(DesignStudioCanvasViewsService $designStudioCanvasViewsService): DesignStudioOverviewGetResponseDto
    {
        $this->requireDesignStudioViewer();
        $responseDto = new DesignStudioOverviewGetResponseDto();
        $responseDto->designStudioOverview = $designStudioCanvasViewsService->getOverview();
        return $responseDto;
    }

    /**
     * Themes (looks) the boards can be shown in.
     * @param DesignStudioThemesGetRequestDto $requestDto
     * @param DesignThemeProviderService $designThemeProviderService
     * @return DesignStudioThemesGetResponseDto
     * @throws ForbiddenException
     */
    #[Get('/themes')]
    #[Summary('DesignStudio Themes List')]
    public function getThemes(
        DesignStudioThemesGetRequestDto $requestDto,
        DesignThemeProviderService $designThemeProviderService
    ): DesignStudioThemesGetResponseDto {
        $this->requireDesignStudioViewer();
        $responseDto = new DesignStudioThemesGetResponseDto();
        $responseDto->designThemes = $designThemeProviderService->findThemes($requestDto->search);
        return $responseDto;
    }

    /**
     * The canvas with its sections and boards as they are in the checkout (position, size, version, status, open
     * threads, delivery URLs). Never the board files themselves.
     * @param DesignStudioCanvasGetRequestDto $requestDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @return DesignStudioCanvasGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Get('/canvases/{projectKey}/{canvasKey}')]
    #[Summary('DesignStudio Canvas Details')]
    public function getCanvas(
        DesignStudioCanvasGetRequestDto $requestDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService
    ): DesignStudioCanvasGetResponseDto {
        $viewer = $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $responseDto = new DesignStudioCanvasGetResponseDto();
        $responseDto->designStudioCanvasView = $designStudioCanvasViewsService->getCanvasView($canvasDefinition, $requestDto->theme, $viewer);
        return $responseDto;
    }

    /**
     * A cheap fingerprint of the canvas — the page polls it while visible and reloads the canvas or the comments only
     * when it changed.
     * @param DesignStudioCanvasGetRequestDto $requestDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @return DesignStudioCanvasRevisionGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    #[Get('/canvases/{projectKey}/{canvasKey}/revision')]
    #[Summary('DesignStudio Canvas Revision')]
    public function getCanvasRevision(
        DesignStudioCanvasGetRequestDto $requestDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService
    ): DesignStudioCanvasRevisionGetResponseDto {
        $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $responseDto = new DesignStudioCanvasRevisionGetResponseDto();
        $responseDto->revision = $designStudioCanvasViewsService->getRevision($canvasDefinition);
        return $responseDto;
    }

    /**
     * Asks for the missing thumbnails of the canvas in a look: files (or refreshes) a render request — boards on
     * screen first — and answers the canvas as getCanvas does. The thumbnails arrive while the render runs. Without a
     * renderer nothing is filed.
     * @param DesignStudioCanvasGetRequestDto $requestDto
     * @param DesignStudioThumbnailRenderRequestPostRequestDto $renderRequestDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @param DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService
     * @return DesignStudioCanvasGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Post('/canvases/{projectKey}/{canvasKey}/thumbnailRenderRequests')]
    #[Summary('DesignStudio Canvas Thumbnail Render Request')]
    public function requestThumbnailRendering(
        DesignStudioCanvasGetRequestDto $requestDto,
        DesignStudioThumbnailRenderRequestPostRequestDto $renderRequestDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService,
        DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService
    ): DesignStudioCanvasGetResponseDto {
        $viewer = $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $themeReference = $renderRequestDto->theme ?? $requestDto->theme ?? $canvasDefinition->referenceTheme ?? null;
        $designStudioThumbnailRenderRequestsService->requestRendering(
            $canvasDefinition,
            $themeReference,
            $renderRequestDto->priorityBoardKeys ?? [],
            $viewer
        );
        $responseDto = new DesignStudioCanvasGetResponseDto();
        $responseDto->designStudioCanvasView = $designStudioCanvasViewsService->getCanvasView($canvasDefinition, $themeReference, $viewer);
        return $responseDto;
    }

    /**
     * Sets the review status of a board (one of the configured statuses) and remembers the board version it was set
     * on.
     * @param DesignStudioBoardRequestDto $requestDto
     * @param DesignStudioBoardStatusPatchRequestDto $statusDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @param DesignStudioBoardStatusesService $designStudioBoardStatusesService
     * @return DesignStudioBoardStatusGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Patch('/canvases/{projectKey}/{canvasKey}/boards/{boardKey}/status', requirements: ['boardKey' => '[^/]+'])]
    #[Summary('DesignStudio Board Status Update')]
    public function setBoardStatus(
        DesignStudioBoardRequestDto $requestDto,
        DesignStudioBoardStatusPatchRequestDto $statusDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService,
        DesignStudioBoardStatusesService $designStudioBoardStatusesService
    ): DesignStudioBoardStatusGetResponseDto {
        $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $designDirectoryBoard = $designStudioCanvasViewsService->getDirectoryBoard($canvasDefinition, $requestDto->boardKey);
        $responseDto = new DesignStudioBoardStatusGetResponseDto();
        $responseDto->designStudioBoardStatus = $designStudioBoardStatusesService->setStatus($canvasDefinition, $designDirectoryBoard, $statusDto->status);
        return $responseDto;
    }
}
