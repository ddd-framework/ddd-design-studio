<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryCanvas;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioBoardView;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioBoardViews;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioCanvasSummaries;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioCanvasSummary;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioCanvasView;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioOverview;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioProjectSummaries;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioProjectSummary;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * What the studio pages show: the boards of the checkout joined with the review state of the database. Nothing here
 * is stored — every call reads the canvas folder (cheap: file hashes are cached by modification time and size) and
 * the comments and statuses of the canvas.
 */
class DesignStudioCanvasViewsService extends Service
{
    /** @var string Fallback of the config `DesignStudio.api.contentBasePath` */
    public const string DEFAULT_CONTENT_BASE_PATH = '/api/admin/designStudio/content';

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @return DesignCanvasDefinition
     * @throws BadRequestException
     * @throws NotFoundException when the canvas is not configured
     */
    public function getCanvasDefinition(string $projectKey, string $canvasKey): DesignCanvasDefinition
    {
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        $canvasDefinition = $designProjectDefinitionsService->getCanvasDefinition($projectKey, $canvasKey);
        if (!$canvasDefinition) {
            throw new NotFoundException('Design canvas not found');
        }
        return $canvasDefinition;
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey
     * @return DesignDirectoryBoard the board as it is in the checkout now
     * @throws InternalErrorException
     * @throws NotFoundException when the canvas has no such board in this checkout
     */
    public function getDirectoryBoard(DesignCanvasDefinition $canvasDefinition, string $boardKey): DesignDirectoryBoard
    {
        foreach ($this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition)->boards->getElements() as $designDirectoryBoard) {
            if ($designDirectoryBoard->boardKey === $boardKey) {
                return $designDirectoryBoard;
            }
        }
        throw new NotFoundException('Design board not found in this checkout');
    }

    /**
     * The canvas page. Opening a canvas also deletes expired threads when that has not happened today.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string|null $themeReference `<themeId>[:<mode>]` the boards are shown in; null = the canvas' reference theme
     * @param Account $viewer
     * @return DesignStudioCanvasView
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function getCanvasView(DesignCanvasDefinition $canvasDefinition, ?string $themeReference, Account $viewer): DesignStudioCanvasView
    {
        $designStudioCommentsService = $this->getDesignStudioCommentsService();
        $designStudioCommentsService->pruneExpiredThreadsIfDue();
        $projectKey = (string)$canvasDefinition->projectKey;
        $canvasKey = (string)$canvasDefinition->canvasKey;
        $themeReference = $themeReference !== null && trim($themeReference) !== '' ? trim($themeReference) : ($canvasDefinition->referenceTheme ?? null);
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        $themeStyle = $designThemeProviderService->getThemeStyleForReference($themeReference);
        if ($themeStyle === null) {
            $themeReference = null;
        }
        $themeHash = DesignThemeProviderService::computeThemeHash($themeStyle);

        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        $designStudioBoardStatusesService = $this->getDesignStudioBoardStatusesService();
        $statuses = $designStudioBoardStatusesService->getStatuses();
        $statusesByBoardKey = [];
        foreach ($designStudioBoardStatusesService->findForCanvas($projectKey, $canvasKey)->getElements() as $designStudioBoardStatus) {
            $statusesByBoardKey[(string)$designStudioBoardStatus->boardKey] = $designStudioBoardStatus;
        }
        $openThreadCounts = [];
        foreach ($designStudioCommentsService->findForCanvas($projectKey, $canvasKey, true)->getElements() as $designStudioComment) {
            if ($designStudioComment->isThreadRoot()) {
                $openThreadCounts[(string)$designStudioComment->boardKey] = ($openThreadCounts[(string)$designStudioComment->boardKey] ?? 0) + 1;
            }
        }
        $designStudioThumbnailsService = $this->getDesignStudioThumbnailsService();
        $isRendererEnabled = $designStudioThumbnailsService->isRendererEnabled();
        $designStudioThumbnails = $isRendererEnabled ? $designStudioThumbnailsService->findForBoards($designDirectoryCanvas->boards, $themeHash) : null;
        /** @var DesignBoardContentService $designBoardContentService */
        $designBoardContentService = DDDService::instance()->getService(DesignBoardContentService::class);
        $tokenQuery = 'token=' . rawurlencode($designBoardContentService->createContentToken($viewer));
        $contentBasePath = $this->getContentBasePath();
        $boardBasePath = $contentBasePath . '/boards/' . rawurlencode($projectKey) . '/' . rawurlencode($canvasKey) . '/';
        $themeQuery = $themeReference !== null ? '&theme=' . rawurlencode($themeReference) : '';

        $designStudioCanvasView = new DesignStudioCanvasView();
        $designStudioCanvasView->projectKey = $projectKey;
        $designStudioCanvasView->canvasKey = $canvasKey;
        $designStudioCanvasView->label = $canvasDefinition->label ?? $designDirectoryCanvas->title ?? $canvasKey;
        $designStudioCanvasView->code = $canvasDefinition->getCodePrefix();
        $designStudioCanvasView->themeReference = $themeReference;
        $designStudioCanvasView->referenceTheme = $canvasDefinition->referenceTheme ?? null;
        $designStudioCanvasView->sections = $designDirectoryCanvas->sections;
        $designStudioCanvasView->boards = new DesignStudioBoardViews();
        $designStudioCanvasView->warnings = $designDirectoryCanvas->warnings;
        $designStudioCanvasView->statuses = $statuses;
        $designStudioCanvasView->isRendererEnabled = $isRendererEnabled;
        foreach ($designDirectoryCanvas->boards->getElements() as $designDirectoryBoard) {
            $boardKey = (string)$designDirectoryBoard->boardKey;
            $designStudioBoardStatus = $statusesByBoardKey[$boardKey] ?? null;
            $designStudioBoardView = new DesignStudioBoardView();
            $designStudioBoardView->boardKey = $boardKey;
            $designStudioBoardView->code = $designDirectoryBoard->code;
            $designStudioBoardView->title = $designDirectoryBoard->title;
            $designStudioBoardView->x = $designDirectoryBoard->x;
            $designStudioBoardView->y = $designDirectoryBoard->y;
            $designStudioBoardView->width = $designDirectoryBoard->width;
            $designStudioBoardView->height = $designDirectoryBoard->height;
            $designStudioBoardView->sectionKey = $designDirectoryBoard->sectionKey;
            $designStudioBoardView->displayOrder = $designDirectoryBoard->displayOrder;
            $designStudioBoardView->contentHash = $designDirectoryBoard->contentHash;
            $designStudioBoardView->status = $designStudioBoardStatus && in_array($designStudioBoardStatus->status, $statuses, true)
                ? $designStudioBoardStatus->status
                : ($statuses[0] ?? null);
            $designStudioBoardView->hasChangedSinceStatus = $designStudioBoardStatus !== null
                && ($designStudioBoardStatus->contentHash ?? null) !== null
                && $designStudioBoardStatus->contentHash !== $designDirectoryBoard->contentHash;
            $designStudioBoardView->openThreadCount = $openThreadCounts[$boardKey] ?? 0;
            if ($designDirectoryBoard->contentHash !== null) {
                // the content hash in the URL makes it change exactly when the board (or its look) changes
                // … and the assets hash when an asset file the board shows changes
                $designStudioBoardView->htmlUrl = $boardBasePath . rawurlencode($boardKey) . '?' . $tokenQuery
                    . '&contentHash=' . $designDirectoryBoard->contentHash
                    . ($designDirectoryBoard->assetsHash !== null ? '&assetsHash=' . $designDirectoryBoard->assetsHash : '')
                    . $themeQuery;
                $thumbnailKey = $designStudioThumbnailsService->computeThumbnailKey(
                    $designDirectoryBoard->contentHash,
                    $themeHash,
                    $designDirectoryBoard->assetsHash
                );
                $designStudioThumbnail = $designStudioThumbnails?->getByThumbnailKey($thumbnailKey);
                if ($designStudioThumbnail) {
                    $thumbnailUrl = $contentBasePath . '/thumbnails/' . $thumbnailKey . '?' . $tokenQuery;
                    $designStudioBoardView->thumbnailUrl = $thumbnailUrl;
                    $designStudioBoardView->thumbnailSmallUrl = $thumbnailUrl . '&size=' . DesignStudioThumbnail::SIZE_SMALL;
                } elseif ($isRendererEnabled) {
                    $designStudioCanvasView->missingThumbnailCount++;
                }
            }
            $designStudioCanvasView->boards->add($designStudioBoardView);
        }
        if ($isRendererEnabled) {
            /** @var DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService */
            $designStudioThumbnailRenderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
            $latestRenderRequest = $designStudioThumbnailRenderRequestsService->findLatestRequest($canvasDefinition, $themeHash);
            $designStudioCanvasView->renderRequestStatus = $latestRenderRequest->status ?? null;
            $designStudioCanvasView->renderRequestError = $latestRenderRequest->lastError ?? null;
        }
        $designStudioCanvasView->revision = $this->computeRevision($canvasDefinition, $designDirectoryCanvas);
        return $designStudioCanvasView;
    }

    /**
     * A cheap fingerprint of the canvas — the page polls it while visible and reloads the canvas or the comments only
     * when it changed. It changes with every board file, position, comment and status.
     * @param DesignCanvasDefinition $canvasDefinition
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getRevision(DesignCanvasDefinition $canvasDefinition): string
    {
        return $this->computeRevision($canvasDefinition, $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition));
    }

    /**
     * The start page: every configured project with its canvases.
     * @return DesignStudioOverview
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getOverview(): DesignStudioOverview
    {
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        $designStudioCommentsService = $this->getDesignStudioCommentsService();
        $designStudioOverview = new DesignStudioOverview();
        $designStudioOverview->projects = new DesignStudioProjectSummaries();
        $designStudioOverview->statuses = $this->getDesignStudioBoardStatusesService()->getStatuses();
        $designStudioOverview->isRendererEnabled = $this->getDesignStudioThumbnailsService()->isRendererEnabled();
        $designStudioOverview->hasThemes = $designThemeProviderService->findThemes()->count() > 0;
        $designStudioOverview->threadIdleDays = (int)(Config::get('DesignStudio.retention.threadIdleDays') ?? DesignStudioCommentsService::DEFAULT_THREAD_IDLE_DAYS);
        $designStudioOverview->expiringThreadCount = $designStudioCommentsService->findExpiringThreads()->count();
        foreach ($designProjectDefinitionsService->getProjectDefinitions()->getElements() as $projectDefinition) {
            $designStudioProjectSummary = new DesignStudioProjectSummary();
            $designStudioProjectSummary->projectKey = $projectDefinition->projectKey;
            $designStudioProjectSummary->label = $projectDefinition->label ?? $projectDefinition->projectKey;
            $designStudioProjectSummary->canvases = new DesignStudioCanvasSummaries();
            foreach ($projectDefinition->canvasDefinitions->getElements() as $canvasDefinition) {
                $designStudioCanvasSummary = new DesignStudioCanvasSummary();
                $designStudioCanvasSummary->canvasKey = $canvasDefinition->canvasKey;
                $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
                $designStudioCanvasSummary->label = $canvasDefinition->label ?? $designDirectoryCanvas->title ?? $canvasDefinition->canvasKey;
                $designStudioCanvasSummary->referenceTheme = $canvasDefinition->referenceTheme ?? null;
                $designStudioCanvasSummary->code = $canvasDefinition->getCodePrefix();
                $designStudioCanvasSummary->boardCount = $designDirectoryCanvas->boards->count();
                foreach ($designStudioCommentsService->findForCanvas((string)$canvasDefinition->projectKey, (string)$canvasDefinition->canvasKey)->getElements() as $designStudioComment) {
                    if (!$designStudioComment->isThreadRoot()) {
                        continue;
                    }
                    if (!($designStudioComment->isResolved ?? false)) {
                        $designStudioCanvasSummary->openThreadCount++;
                    }
                    if ($designStudioComment->isDecision ?? false) {
                        $designStudioCanvasSummary->decisionCount++;
                    }
                }
                $designStudioProjectSummary->canvases->add($designStudioCanvasSummary);
            }
            $designStudioOverview->projects->add($designStudioProjectSummary);
        }
        return $designStudioOverview;
    }

    /**
     * @return string path of the content routes, without a trailing slash
     */
    public function getContentBasePath(): string
    {
        return rtrim((string)(Config::get('DesignStudio.api.contentBasePath') ?: static::DEFAULT_CONTENT_BASE_PATH), '/');
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryCanvas $designDirectoryCanvas
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function computeRevision(DesignCanvasDefinition $canvasDefinition, DesignDirectoryCanvas $designDirectoryCanvas): string
    {
        $projectKey = (string)$canvasDefinition->projectKey;
        $canvasKey = (string)$canvasDefinition->canvasKey;
        $parts = [];
        foreach ($designDirectoryCanvas->boards->getElements() as $designDirectoryBoard) {
            $parts[] = implode(':', [
                $designDirectoryBoard->boardKey,
                $designDirectoryBoard->contentHash,
                $designDirectoryBoard->x,
                $designDirectoryBoard->y,
                $designDirectoryBoard->width,
                $designDirectoryBoard->height,
                $designDirectoryBoard->sectionKey,
            ]);
        }
        foreach ($designDirectoryCanvas->sections->getElements() as $designDirectorySection) {
            $parts[] = implode(':', [$designDirectorySection->sectionKey, $designDirectorySection->title, $designDirectorySection->x, $designDirectorySection->y]);
        }
        $parts[] = $this->getDesignStudioCommentsService()->getCanvasFingerprint($projectKey, $canvasKey);
        foreach ($this->getDesignStudioBoardStatusesService()->findForCanvas($projectKey, $canvasKey)->getElements() as $designStudioBoardStatus) {
            $parts[] = implode(':', [$designStudioBoardStatus->boardKey, $designStudioBoardStatus->status, $designStudioBoardStatus->contentHash]);
        }
        return sha1(implode("\n", $parts));
    }

    /**
     * @return DesignDirectoryReaderService
     */
    protected function getDesignDirectoryReaderService(): DesignDirectoryReaderService
    {
        return DDDService::instance()->getService(DesignDirectoryReaderService::class);
    }

    /**
     * @return DesignStudioCommentsService
     */
    protected function getDesignStudioCommentsService(): DesignStudioCommentsService
    {
        return DDDService::instance()->getService(DesignStudioCommentsService::class);
    }

    /**
     * @return DesignStudioBoardStatusesService
     */
    protected function getDesignStudioBoardStatusesService(): DesignStudioBoardStatusesService
    {
        return DDDService::instance()->getService(DesignStudioBoardStatusesService::class);
    }

    /**
     * @return DesignStudioThumbnailsService
     */
    protected function getDesignStudioThumbnailsService(): DesignStudioThumbnailsService
    {
        return DDDService::instance()->getService(DesignStudioThumbnailsService::class);
    }
}
