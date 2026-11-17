<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderFailure;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderFailures;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequests;
use DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsMessage;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequests;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use ImagickException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * Renders the missing thumbnails of a canvas on demand.
 *
 * - Looking at a canvas whose thumbnails are incomplete files a request (at most one open request per canvas and
 *   theme hash; a second look only refreshes the priority boards).
 * - A request runs in shards (config `DesignStudio.renderer.concurrency`), one message each on separate consumers: a
 *   board belongs to shard crc32(boardKey) % n; the next board of a shard is already rendering while the previous
 *   one is stored. A run works for at most RUN_BUDGET_SECONDS, then hands over to a fresh message of its shard.
 * - The boards are read from the checkout of the worker at each run: a board that changed meanwhile is rendered in
 *   its new version (a follow-up run reads them again when one changed during the run).
 * - A shard that is through watches the boards of the other shards until nothing renderable is missing; then the
 *   request closes. A request never stays open without a queued or running message.
 * - Renderer busy / restarting / unreachable (401, 404, 429, 502–504, transport): the request waits
 *   (WAITING_FOR_RENDERER) and is retried with exponential backoff; thumbnails stay as they are.
 * - One picture that fails (the renderer refuses it, its render or the store of its thumbnail throws) never blocks
 *   the others: the run skips it, renders the rest, tries the failed ones once more at its end and records those that
 *   failed again on the request (failedBoards, with the error per picture). A recorded picture is not tried again by
 *   this request (a new version of the board is). The request ends DONE when it rendered at least one picture, FAILED
 *   when it ends with an error and rendered none.
 *
 * @method DesignStudioThumbnailRenderRequest find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioThumbnailRenderRequests findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioThumbnailRenderRequest getEntityRepoClassInstance()
 * @method DBDesignStudioThumbnailRenderRequests getEntitySetRepoClassInstance()
 */
class DesignStudioThumbnailRenderRequestsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioThumbnailRenderRequest::class;

    /** @var int Seconds a run renders before it continues in a new message */
    public const int RUN_BUDGET_SECONDS = 90;

    /** @var int Waiting runs in a row before the request fails (backoff caps at MAX_BACKOFF_SECONDS: about 2 hours) */
    public const int MAX_ATTEMPTS = 30;

    /** @var int First retry delay in seconds after a renderer failure (doubles per attempt) */
    public const int FIRST_BACKOFF_SECONDS = 30;

    /** @var int Longest retry delay in seconds: below STALLED_AFTER_SECONDS, so a waiting request keeps its slot */
    public const int MAX_BACKOFF_SECONDS = 240;

    /** @var int Boards rendered at once without a configured concurrency */
    public const int RENDER_CONCURRENCY_DEFAULT = 2;

    /** @var int Upper bound of the concurrency (the renderer answers 429 beyond its queue anyway) */
    public const int RENDER_CONCURRENCY_MAXIMUM = 8;

    /** @var int Most priority board keys kept on a request */
    public const int MAX_PRIORITY_BOARDS = 200;

    /**
     * @var int Seconds without any write after which an open request counts as lost and is replaced. Every run, every
     *          stored thumbnail, every retry and every watching look writes it; longer than one run plus the render of
     *          one board, and than the longest backoff (with its jitter)
     */
    public const int STALLED_AFTER_SECONDS = 300;

    /** @var int Seconds until a shard whose board changed during its run reads the boards again */
    public const int CHANGED_BOARD_FOLLOW_UP_DELAY_SECONDS = 10;

    /** @var int Follow-up runs in a row for changed boards; then the board counts as not renderable for this request */
    public const int MAX_CHANGED_BOARD_FOLLOW_UPS = 10;

    /** @var int Seconds between two looks of a finished shard at the boards the other shards still render */
    public const int REMAINING_BOARDS_WATCH_DELAY_SECONDS = 60;

    /**
     * @var int Seconds the remaining boards may stay the same (no shard waiting for the renderer) before the request
     *          closes: longer than one run plus the render of one board, so a working shard always shows progress
     */
    public const int REMAINING_BOARDS_SETTLED_AFTER_SECONDS = 180;

    /** @var string finishRender(): the picture's thumbnail is stored */
    public const string RENDER_RESULT_STORED = 'STORED';

    /** @var string finishRender(): this picture failed (recorded in the run's failures); the run goes on */
    public const string RENDER_RESULT_FAILED = 'FAILED';

    /** @var string finishRender(): the renderer is busy or down; the request waits and this run ends */
    public const string RENDER_RESULT_RENDERER_UNAVAILABLE = 'RENDERER_UNAVAILABLE';

    /** @var int Pictures named in the request's lastError when it ends with failed pictures */
    public const int FAILED_BOARDS_NAMED_IN_LAST_ERROR = 3;

    /**
     * Files (or refreshes) the render request of a canvas in a look; null when the renderer is off, nothing is
     * missing or the theme provider answers a different style on every call (DesignThemeProviderService::isThemeStyleStable()).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string|null $themeReference `<themeId>[:<mode>]`, null = no theme
     * @param string[] $priorityBoardKeys the boards on screen (rendered first)
     * @param Account|null $requestedBy
     * @return DesignStudioThumbnailRenderRequest|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function requestRendering(
        DesignCanvasDefinition $canvasDefinition,
        ?string $themeReference,
        array $priorityBoardKeys,
        ?Account $requestedBy
    ): ?DesignStudioThumbnailRenderRequest {
        $designStudioThumbnailsService = $this->getDesignStudioThumbnailsService();
        if (!$designStudioThumbnailsService->isRendererEnabled()) {
            return null;
        }
        $themeStyle = $this->getThemeStyle($themeReference);
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        if (!$designThemeProviderService->isThemeStyleStable($themeReference, $themeStyle)) {
            // every look would get a hash of its own: nothing is filed, the canvas view and the console name the cause
            return null;
        }
        $themeHash = DesignThemeProviderService::computeThemeHash($themeStyle);
        $priorityBoardKeys = array_slice(array_values(array_unique(array_filter(array_map('strval', $priorityBoardKeys)))), 0, static::MAX_PRIORITY_BOARDS);
        $priorityList = implode("\n", $priorityBoardKeys);
        $latestRenderRequest = $this->findLatestRequest($canvasDefinition, $themeHash);
        if ($latestRenderRequest && $latestRenderRequest->isOpen() && $this->isStalled($latestRenderRequest)) {
            // its messages are gone (no consumer handled them, or the queue was emptied): close it, a new request takes over
            $this->setStatus($latestRenderRequest, DesignStudioThumbnailRenderRequest::STATUS_FAILED, 'no render run for ' . static::STALLED_AFTER_SECONDS . ' s, replaced by a new request');
            $latestRenderRequest = null;
        }
        if ($latestRenderRequest && $latestRenderRequest->isOpen()) {
            if ($priorityList !== '' && $priorityList !== ($latestRenderRequest->priorityBoardKeys ?? '')) {
                $latestRenderRequest->priorityBoardKeys = $priorityList;
                $latestRenderRequest->updatePartialIgnoringRights('priorityBoardKeys');
            }
            return $latestRenderRequest;
        }
        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        if (!$designStudioThumbnailsService->findBoardsWithoutThumbnail($designDirectoryCanvas->boards, $themeHash)->count()) {
            return null;
        }
        $renderRequest = new DesignStudioThumbnailRenderRequest();
        $renderRequest->projectKey = $canvasDefinition->projectKey;
        $renderRequest->canvasKey = $canvasDefinition->canvasKey;
        $renderRequest->themeReference = $themeReference !== null && trim($themeReference) !== '' ? trim($themeReference) : null;
        $renderRequest->themeHash = $themeHash;
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_QUEUED;
        $renderRequest->priorityBoardKeys = $priorityList !== '' ? $priorityList : null;
        $renderRequest->attempts = 0;
        $renderRequest->requestedByAccountId = $requestedBy?->id;
        $renderRequest = $this->update($renderRequest);
        $this->dispatchAllShards($renderRequest);
        return $renderRequest;
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $themeHash
     * @return DesignStudioThumbnailRenderRequest|null the newest request of the canvas for the look (any status)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findLatestRequest(DesignCanvasDefinition $canvasDefinition, string $themeHash): ?DesignStudioThumbnailRenderRequest
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.projectKey = :projectKey")
            ->andWhere("$alias.canvasKey = :canvasKey")
            ->andWhere("$alias.themeHash = :themeHash")
            ->setParameter('projectKey', $canvasDefinition->projectKey)
            ->setParameter('canvasKey', $canvasDefinition->canvasKey)
            ->setParameter('themeHash', $themeHash)
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults(1);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * One render run of a request (the Messenger job) for one shard: renders the shard's missing boards (priority
     * boards first) until the run budget is used up, then continues in a new message of the same shard; the request
     * is closed when nothing is missing any more.
     *
     * - A board whose file or assets changed between the listing and its render is not rendered in the old version:
     *   a follow-up run of the shard reads the boards again shortly after (at most MAX_CHANGED_BOARD_FOLLOW_UPS in a
     *   row).
     * - A shard that is through while other shards' boards are still missing watches them in follow-up runs: the
     *   request closes when nothing is missing, or when the missing boards stayed the same for
     *   REMAINING_BOARDS_SETTLED_AFTER_SECONDS while no shard waits for the renderer (they cannot be rendered).
     * @param int $renderRequestId
     * @param int $shard 0 … $shardCount - 1
     * @param int $shardCount
     * @param int $changedBoardFollowUpCount follow-up runs in a row for changed boards before this one
     * @param string|null $remainingBoardsFingerprint the missing boards a watching run saw last; null = no watching run
     * @param int|null $remainingBoardsUnchangedSince since when (Unix time) the missing boards are the same
     * @return void
     * @throws BadRequestException
     * @throws ImagickException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function processRequest(
        int $renderRequestId,
        int $shard = 0,
        int $shardCount = 1,
        int $changedBoardFollowUpCount = 0,
        ?string $remainingBoardsFingerprint = null,
        ?int $remainingBoardsUnchangedSince = null
    ): void {
        $shardCount = max(1, $shardCount);
        $shard = max(0, min($shardCount - 1, $shard));
        $renderRequest = $this->find($renderRequestId, false);
        if (!$renderRequest || !$renderRequest->isOpen()) {
            return;
        }
        // every run (a redelivered one too) is a sign of life: isStalled() measures from the last one
        $this->writeRequestColumns($renderRequest);
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        $canvasDefinition = $designProjectDefinitionsService->getCanvasDefinition((string)$renderRequest->projectKey, (string)$renderRequest->canvasKey);
        if (!$canvasDefinition) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_FAILED, 'the canvas is not configured in this checkout');
            return;
        }
        // two canvas pages filed the same look at the same moment: the newest request carries on, older ones close
        $latestRenderRequest = $this->findLatestRequest($canvasDefinition, (string)$renderRequest->themeHash);
        if ($latestRenderRequest && $latestRenderRequest->id !== $renderRequest->id) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_DONE, "merged into request #$latestRenderRequest->id");
            return;
        }
        $themeStyle = $this->getThemeStyle($renderRequest->themeReference ?? null);
        $themeHash = DesignThemeProviderService::computeThemeHash($themeStyle);
        if ($themeHash !== $renderRequest->themeHash) {
            /** @var DesignThemeProviderService $designThemeProviderService */
            $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
            $this->closeRequest(
                $renderRequest,
                null,
                $designThemeProviderService->isThemeStyleStable($renderRequest->themeReference ?? null, $themeStyle)
                    // the theme's colours changed since the request was filed: the next look at the canvas files a new one
                    ? DesignThemeProviderService::THEME_CHANGED_ERROR
                    // a provider that answers a different style on every call (an embedded timestamp, …): no picture
                    // can ever be matched to a look, the provider has to be fixed
                    : DesignThemeProviderService::THEME_STYLE_UNSTABLE_ERROR
            );
            return;
        }
        $designStudioThumbnailsService = $this->getDesignStudioThumbnailsService();
        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        $boardsWithoutThumbnail = $designStudioThumbnailsService->findBoardsWithoutThumbnail(
            $designDirectoryCanvas->boards,
            $themeHash,
            $renderRequest->getPriorityBoardKeyList()
        );
        // pictures this request already failed (any shard) are not tried again; a new version of the board is
        $renderFailures = $this->findRenderFailures($renderRequest);
        $renderableBoards = static::findBoardsWithoutRecordedFailure($boardsWithoutThumbnail, $renderFailures);
        if (!$renderableBoards->count()) {
            $this->closeRequest($renderRequest, $renderFailures);
            return;
        }
        // this shard's boards: stable by board key (a hand-over or backoff never moves a board to another shard while
        // that one renders it), priority order kept within the shard
        $shardBoards = array_values(array_filter(
            $renderableBoards->getElements(),
            static fn(DesignDirectoryBoard $designDirectoryBoard): bool => static::getShardOfBoard($designDirectoryBoard->getRenderTargetKey(), $shardCount) === $shard
        ));
        if (!$shardBoards) {
            // the other shards render the rest; a shard that rendered boards watches them, a watching run goes on watching
            if ($remainingBoardsFingerprint !== null) {
                $this->closeOrWatchRequest($renderRequest, $boardsWithoutThumbnail, $shard, $shardCount, $remainingBoardsFingerprint, $remainingBoardsUnchangedSince);
            }
            return;
        }
        if ($renderRequest->status !== DesignStudioThumbnailRenderRequest::STATUS_RUNNING) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_RUNNING, null);
        }
        $startedAt = time();
        $renderedBoardCount = 0;
        $changedBoardKeys = [];
        // pictures that failed once in this run: skipped, tried once more at the end of the run
        $boardsToRetry = new DesignDirectoryBoards();
        $runFailures = new DesignStudioThumbnailRenderFailures();
        // one board at a time per shard; the next one is already rendering while the previous one is stored
        $pendingBoard = null;
        $pendingRenderer = null;
        foreach ($shardBoards as $designDirectoryBoard) {
            $isOverBudget = time() - $startedAt >= static::RUN_BUDGET_SECONDS;
            $startedRenderer = null;
            if (!$isOverBudget) {
                try {
                    $startedRenderer = $this->startRender($canvasDefinition, $designDirectoryBoard, $themeHash, $themeStyle);
                    if ($startedRenderer === null) {
                        $changedBoardKeys[] = (string)$designDirectoryBoard->boardKey;
                    }
                } catch (Throwable $throwable) {
                    $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($designDirectoryBoard, 'rendering failed: ' . $throwable->getMessage());
                    $runFailures->add($designStudioThumbnailRenderFailure);
                    $boardsToRetry->add($designDirectoryBoard);
                }
            }
            if ($pendingRenderer !== null) {
                $renderResult = $this->finishRender($renderRequest, $pendingBoard, $pendingRenderer, $shard, $shardCount, $runFailures);
                if ($renderResult === static::RENDER_RESULT_RENDERER_UNAVAILABLE) {
                    $this->abandonRender($startedRenderer);
                    return;
                }
                if ($renderResult === static::RENDER_RESULT_STORED) {
                    $renderedBoardCount++;
                } else {
                    $boardsToRetry->add($pendingBoard);
                }
            }
            $pendingBoard = $startedRenderer ? $designDirectoryBoard : null;
            $pendingRenderer = $startedRenderer;
            if ($isOverBudget) {
                // hand over: a fresh message of this shard continues (and sees priorities refreshed meanwhile)
                $this->logRun($renderRequest, $shard, $shardCount, $renderedBoardCount, $startedAt);
                if (!$this->retryFailedBoards($renderRequest, $canvasDefinition, $boardsToRetry, $themeHash, $themeStyle, $shard, $shardCount)) {
                    return;
                }
                $this->dispatchRun($renderRequest, 0, $shard, $shardCount);
                return;
            }
        }
        if ($pendingRenderer !== null) {
            $renderResult = $this->finishRender($renderRequest, $pendingBoard, $pendingRenderer, $shard, $shardCount, $runFailures);
            if ($renderResult === static::RENDER_RESULT_RENDERER_UNAVAILABLE) {
                return;
            }
            if ($renderResult === static::RENDER_RESULT_STORED) {
                $renderedBoardCount++;
            } else {
                $boardsToRetry->add($pendingBoard);
            }
        }
        $this->logRun($renderRequest, $shard, $shardCount, $renderedBoardCount, $startedAt);
        if (!$this->retryFailedBoards($renderRequest, $canvasDefinition, $boardsToRetry, $themeHash, $themeStyle, $shard, $shardCount)) {
            return;
        }
        if ($changedBoardKeys !== []) {
            if ($changedBoardFollowUpCount < static::MAX_CHANGED_BOARD_FOLLOW_UPS) {
                // a board changed between the listing and its render: read the boards again and render the new version
                $this->dispatchRun($renderRequest, static::CHANGED_BOARD_FOLLOW_UP_DELAY_SECONDS, $shard, $shardCount, $changedBoardFollowUpCount + 1);
                return;
            }
            $this->storeLastError($renderRequest, implode(', ', $changedBoardKeys) . ': changed during every run, not rendered');
        }
        // this shard is through: the boards are read again, a board that changed meanwhile is missing in its new version
        $remainingBoards = $designStudioThumbnailsService->findBoardsWithoutThumbnail(
            $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition)->boards,
            $themeHash
        );
        $this->closeOrWatchRequest($renderRequest, $remainingBoards, $shard, $shardCount, $remainingBoardsFingerprint, $remainingBoardsUnchangedSince);
    }

    /**
     * Tries the pictures that failed once in this run a second time (render and store); those that fail again are
     * recorded on the request with their error and not tried again by it.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoards $boardsToRetry
     * @param string $themeHash
     * @param string|null $themeStyle
     * @param int $shard
     * @param int $shardCount
     * @return bool false when the renderer became unavailable (the request waits, this run ends)
     */
    protected function retryFailedBoards(
        DesignStudioThumbnailRenderRequest $renderRequest,
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoards $boardsToRetry,
        string $themeHash,
        ?string $themeStyle,
        int $shard,
        int $shardCount
    ): bool {
        $finalFailures = new DesignStudioThumbnailRenderFailures();
        foreach ($boardsToRetry->getElements() as $designDirectoryBoard) {
            $retryFailures = new DesignStudioThumbnailRenderFailures();
            try {
                $renderer = $this->startRender($canvasDefinition, $designDirectoryBoard, $themeHash, $themeStyle);
            } catch (Throwable $throwable) {
                $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($designDirectoryBoard, 'rendering failed: ' . $throwable->getMessage());
                $finalFailures->add($designStudioThumbnailRenderFailure);
                continue;
            }
            if ($renderer === null) {
                // the board changed meanwhile: its new version is missing and read again by a later run
                continue;
            }
            $renderResult = $this->finishRender($renderRequest, $designDirectoryBoard, $renderer, $shard, $shardCount, $retryFailures);
            if ($renderResult === static::RENDER_RESULT_RENDERER_UNAVAILABLE) {
                return false;
            }
            if ($renderResult === static::RENDER_RESULT_FAILED && $retryFailures->count()) {
                $designStudioThumbnailRenderFailure = $retryFailures->first();
                $finalFailures->add($designStudioThumbnailRenderFailure);
            }
        }
        if ($finalFailures->count()) {
            $this->recordRenderFailures($renderRequest, $finalFailures);
        }
        return true;
    }

    /**
     * Waits for a render that is no longer needed (the run ends); its result and errors are dropped.
     * @param ArgusDesignBoardRenderedThumbnails|null $renderer
     * @return void
     */
    protected function abandonRender(?ArgusDesignBoardRenderedThumbnails $renderer): void
    {
        if ($renderer === null) {
            return;
        }
        try {
            $renderer->finishRender();
        } catch (Throwable) {
            // nothing depends on it
        }
    }

    /**
     * @param DesignDirectoryBoards $boardsWithoutThumbnail
     * @param DesignStudioThumbnailRenderFailures $renderFailures
     * @return DesignDirectoryBoards the boards without a recorded failure of their current version
     */
    public static function findBoardsWithoutRecordedFailure(
        DesignDirectoryBoards $boardsWithoutThumbnail,
        DesignStudioThumbnailRenderFailures $renderFailures
    ): DesignDirectoryBoards {
        if (!$renderFailures->count()) {
            return $boardsWithoutThumbnail;
        }
        $renderableBoards = new DesignDirectoryBoards();
        foreach ($boardsWithoutThumbnail->getElements() as $designDirectoryBoard) {
            if (!$renderFailures->findForBoard($designDirectoryBoard)) {
                $renderableBoards->add($designDirectoryBoard);
            }
        }
        return $renderableBoards;
    }

    /**
     * Ends a request: FAILED when it ends with an error (its own lastError, failed pictures, $error) and rendered no
     * picture, otherwise DONE (failed pictures are named in lastError and kept in failedBoards).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignStudioThumbnailRenderFailures|null $renderFailures the request's recorded failures, null = read them
     * @param string|null $error why the request ends, when not because nothing is left
     * @return void
     */
    protected function closeRequest(
        DesignStudioThumbnailRenderRequest $renderRequest,
        ?DesignStudioThumbnailRenderFailures $renderFailures = null,
        ?string $error = null
    ): void {
        $renderFailures ??= $this->findRenderFailures($renderRequest);
        $lastError = $error ?? static::describeRenderFailures($renderFailures) ?? ($renderRequest->lastError ?? null);
        $status = $lastError !== null && $this->findRenderedThumbnailCount($renderRequest) === 0
            ? DesignStudioThumbnailRenderRequest::STATUS_FAILED
            : DesignStudioThumbnailRenderRequest::STATUS_DONE;
        $this->setStatus($renderRequest, $status, $lastError !== null ? mb_substr($lastError, 0, 1000) : null);
    }

    /**
     * @param DesignStudioThumbnailRenderFailures $renderFailures
     * @return string|null `2 picture(s) failed: a.html: …; b.html: …`, null without failures
     */
    public static function describeRenderFailures(DesignStudioThumbnailRenderFailures $renderFailures): ?string
    {
        if (!$renderFailures->count()) {
            return null;
        }
        $namedFailures = [];
        foreach (array_slice($renderFailures->getElements(), 0, static::FAILED_BOARDS_NAMED_IN_LAST_ERROR) as $designStudioThumbnailRenderFailure) {
            $namedFailures[] = $designStudioThumbnailRenderFailure->renderTargetKey . ': ' . $designStudioThumbnailRenderFailure->error;
        }
        return $renderFailures->count() . ' picture(s) failed: ' . implode('; ', $namedFailures)
            . ($renderFailures->count() > count($namedFailures) ? '; …' : '');
    }

    /**
     * Closes a request when no shard has anything left that can be rendered; otherwise a follow-up run of this shard
     * looks again after REMAINING_BOARDS_WATCH_DELAY_SECONDS. Missing boards of other shards that stay the same for
     * REMAINING_BOARDS_SETTLED_AFTER_SECONDS while no shard waits for the renderer cannot be rendered: the request
     * closes then too (a shard that skipped or failed them, or whose message is gone, leaves no request open).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignDirectoryBoards $remainingBoards the boards still without a thumbnail
     * @param int $shard
     * @param int $shardCount
     * @param string|null $remainingBoardsFingerprint the missing boards the previous watching run saw
     * @param int|null $remainingBoardsUnchangedSince
     * @return void
     */
    protected function closeOrWatchRequest(
        DesignStudioThumbnailRenderRequest $renderRequest,
        DesignDirectoryBoards $remainingBoards,
        int $shard,
        int $shardCount,
        ?string $remainingBoardsFingerprint,
        ?int $remainingBoardsUnchangedSince
    ): void {
        // pictures a shard recorded as failed are not rendered any more by this request
        $renderFailures = $this->findRenderFailures($renderRequest);
        $remainingBoards = static::findBoardsWithoutRecordedFailure($remainingBoards, $renderFailures);
        $hasBoardOfAnotherShard = false;
        foreach ($remainingBoards->getElements() as $remainingBoard) {
            if (static::getShardOfBoard($remainingBoard->getRenderTargetKey(), $shardCount) !== $shard) {
                $hasBoardOfAnotherShard = true;
                break;
            }
        }
        if (!$hasBoardOfAnotherShard) {
            // nothing missing, or what is left in this shard could not be rendered (lastError names the board)
            $this->closeRequest($renderRequest, $renderFailures);
            return;
        }
        $currentTimestamp = $this->getCurrentTimestamp();
        $currentFingerprint = static::computeRemainingBoardsFingerprint($remainingBoards);
        if ($currentFingerprint !== $remainingBoardsFingerprint || $remainingBoardsUnchangedSince === null) {
            $remainingBoardsUnchangedSince = $currentTimestamp;
        } elseif (
            $renderRequest->status !== DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER
            && $currentTimestamp - $remainingBoardsUnchangedSince >= static::REMAINING_BOARDS_SETTLED_AFTER_SECONDS
        ) {
            // no shard renders them any more
            $this->closeRequest(
                $renderRequest,
                $renderFailures,
                static::describeRenderFailures($renderFailures) ?? $renderRequest->lastError ?? ($remainingBoards->count() . ' board(s) could not be rendered')
            );
            return;
        }
        $this->dispatchRun(
            $renderRequest,
            static::REMAINING_BOARDS_WATCH_DELAY_SECONDS,
            $shard,
            $shardCount,
            0,
            $currentFingerprint,
            $remainingBoardsUnchangedSince
        );
    }

    /**
     * @param DesignDirectoryBoards $remainingBoards
     * @return string changes when a board is rendered, added, removed or changed
     */
    public static function computeRemainingBoardsFingerprint(DesignDirectoryBoards $remainingBoards): string
    {
        $boardVersions = [];
        foreach ($remainingBoards->getElements() as $remainingBoard) {
            $boardVersions[] = $remainingBoard->getRenderTargetKey() . ':' . $remainingBoard->contentHash . ':' . ($remainingBoard->assetsHash ?? '');
        }
        sort($boardVersions);
        return hash('sha256', implode("\n", $boardVersions));
    }

    /**
     * @return int the current Unix time (a seam for tests)
     */
    protected function getCurrentTimestamp(): int
    {
        return time();
    }

    /**
     * @param string $boardKey the board key, of a device frame DesignDirectoryBoard::getRenderTargetKey()
     * @param int $shardCount
     * @return int the shard (0 … $shardCount - 1) that renders a board
     */
    public static function getShardOfBoard(string $boardKey, int $shardCount): int
    {
        return crc32($boardKey) % max(1, $shardCount);
    }

    /**
     * @return int shards per request = renderer calls in flight (config `DesignStudio.renderer.concurrency`,
     *             overridden by the env variable named in `concurrencyEnv`)
     */
    public function getRenderConcurrency(): int
    {
        $concurrencyEnv = Config::get('DesignStudio.renderer.concurrencyEnv');
        $configured = $concurrencyEnv ? Config::getEnv((string)$concurrencyEnv) : null;
        if (!is_numeric($configured) || (int)$configured < 1) {
            $configured = Config::get('DesignStudio.renderer.concurrency');
        }
        return is_numeric($configured) && (int)$configured > 0
            ? min((int)$configured, static::RENDER_CONCURRENCY_MAXIMUM)
            : static::RENDER_CONCURRENCY_DEFAULT;
    }

    /**
     * Starts the shards of a request (one message each).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return void
     */
    public function dispatchAllShards(DesignStudioThumbnailRenderRequest $renderRequest): void
    {
        $shardCount = $this->getRenderConcurrency();
        for ($shard = 0; $shard < $shardCount; $shard++) {
            $this->dispatchRun($renderRequest, 0, $shard, $shardCount);
        }
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param int $delaySeconds
     * @param int $shard
     * @param int $shardCount
     * @param int $changedBoardFollowUpCount follow-up runs in a row for changed boards
     * @param string|null $remainingBoardsFingerprint set for a watching run
     * @param int|null $remainingBoardsUnchangedSince
     * @return void
     */
    public function dispatchRun(
        DesignStudioThumbnailRenderRequest $renderRequest,
        int $delaySeconds,
        int $shard = 0,
        int $shardCount = 1,
        int $changedBoardFollowUpCount = 0,
        ?string $remainingBoardsFingerprint = null,
        ?int $remainingBoardsUnchangedSince = null
    ): void {
        $message = new RenderDesignStudioThumbnailsMessage(
            (int)$renderRequest->id,
            $shard,
            $shardCount,
            $changedBoardFollowUpCount,
            $remainingBoardsFingerprint,
            $remainingBoardsUnchangedSince
        );
        $message->dispatch($delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : []);
    }

    /**
     * Sends a board to the renderer; null when its file cannot be read any more.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param string $themeHash
     * @param string|null $themeStyle
     * @return ArgusDesignBoardRenderedThumbnails|null
     */
    protected function startRender(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $designDirectoryBoard,
        string $themeHash,
        ?string $themeStyle
    ): ?ArgusDesignBoardRenderedThumbnails {
        $boardContent = $this->readBoardContentForRender($canvasDefinition, $designDirectoryBoard);
        if ($boardContent === null) {
            return null;
        }
        $renderer = new ArgusDesignBoardRenderedThumbnails();
        $renderer->html = $boardContent;
        $renderer->width = max(1, (int)$designDirectoryBoard->width);
        $renderer->height = max(1, (int)$designDirectoryBoard->height);
        $renderer->themeHash = $themeHash;
        $renderer->themeStyle = $themeStyle ?? '';
        // a device frame of a responsive board: the whole page at the device's width
        $renderer->fullPage = $designDirectoryBoard->frameDeviceKey !== null;
        // a canvas with board scripts: the picture after they ran (script files are inlined like images)
        $renderer->runsScripts = $designDirectoryBoard->runsScripts;
        $renderer->startRender();
        return $renderer;
    }

    /**
     * The board's HTML with its asset files inlined, in exactly the version the listing names; null when the file or an
     * asset file changed since the listing (or cannot be read any more).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @return string|null
     */
    protected function readBoardContentForRender(DesignCanvasDefinition $canvasDefinition, DesignDirectoryBoard $designDirectoryBoard): ?string
    {
        $boardContent = $this->getDesignDirectoryReaderService()->readBoardContent($canvasDefinition, (string)$designDirectoryBoard->boardKey);
        // only the version the key names is rendered; a file that changed since the listing is read again
        if ($boardContent === null || DesignDirectoryReaderService::computeContentHash($boardContent) !== $designDirectoryBoard->contentHash) {
            return null;
        }
        $assetPaths = DesignBoardAssetsService::findReferencedAssetPaths($boardContent);
        if ($assetPaths === []) {
            return $boardContent;
        }
        /** @var DesignBoardAssetsService $designBoardAssetsService */
        $designBoardAssetsService = DDDService::instance()->getService(DesignBoardAssetsService::class);
        // the same for the asset files: the key names their version too
        $assetsHash = $designBoardAssetsService->findCurrentAssetReferences($canvasDefinition, $assetPaths)->computeAssetsHash();
        if ($assetsHash !== $designDirectoryBoard->assetsHash) {
            return null;
        }
        // the renderer loads nothing from the network: the asset files travel inside the board
        return $designBoardAssetsService->inlineAssetReferences($boardContent, $canvasDefinition);
    }

    /**
     * Waits for a board's render and stores its thumbnail. Anything that goes wrong with this one picture (the renderer
     * refuses it, the render or the store throws — whatever the exception) is a failure of the picture, added to
     * $runFailures; only a busy / unreachable renderer ends the run.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param ArgusDesignBoardRenderedThumbnails $renderer
     * @param int $shard
     * @param int $shardCount
     * @param DesignStudioThumbnailRenderFailures $runFailures receives the failure of this picture
     * @return string RENDER_RESULT_*
     */
    protected function finishRender(
        DesignStudioThumbnailRenderRequest $renderRequest,
        DesignDirectoryBoard $designDirectoryBoard,
        ArgusDesignBoardRenderedThumbnails $renderer,
        int $shard,
        int $shardCount,
        DesignStudioThumbnailRenderFailures $runFailures
    ): string {
        try {
            $renderer->finishRender();
        } catch (Throwable $throwable) {
            $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($designDirectoryBoard, 'rendering failed: ' . $throwable->getMessage());
            $runFailures->add($designStudioThumbnailRenderFailure);
            return static::RENDER_RESULT_FAILED;
        }
        if ($renderer->failureReason !== null) {
            if ($renderer->isRetryableFailure()) {
                // 429 (renderer queue full), 401 / 404 / 502–504 or transport: back off, nothing lost
                $this->waitForRenderer($renderRequest, $renderer->failureStatusCode, (string)$renderer->failureReason, $shard, $shardCount);
                return static::RENDER_RESULT_RENDERER_UNAVAILABLE;
            }
            // this board cannot be rendered (e.g. 400 invalid HTML): the others still can
            $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($designDirectoryBoard, "HTTP $renderer->failureStatusCode $renderer->failureReason");
            $runFailures->add($designStudioThumbnailRenderFailure);
            return static::RENDER_RESULT_FAILED;
        }
        try {
            $this->getDesignStudioThumbnailsService()->storeThumbnail($designDirectoryBoard, $renderer->themeHash, (string)$renderer->png);
        } catch (Throwable $throwable) {
            // the object store failed or timed out, the answer is no image, …: this picture only
            $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($designDirectoryBoard, 'storing the thumbnail failed: ' . $throwable->getMessage());
            $runFailures->add($designStudioThumbnailRenderFailure);
            return static::RENDER_RESULT_FAILED;
        }
        $this->countRenderedThumbnail($renderRequest);
        if ($renderRequest->attempts) {
            $renderRequest->attempts = 0;
            $this->writeRequestColumns($renderRequest, 'attempts');
        } else {
            $this->writeRequestColumns($renderRequest);
        }
        return static::RENDER_RESULT_STORED;
    }

    /**
     * Appends failed pictures to the request's failedBoards with one atomic statement (shards never overwrite each
     * other's failures) and names them in lastError.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignStudioThumbnailRenderFailures $renderFailures
     * @return void
     */
    protected function recordRenderFailures(DesignStudioThumbnailRenderRequest $renderRequest, DesignStudioThumbnailRenderFailures $renderFailures): void
    {
        $storedLines = '';
        foreach ($renderFailures->getElements() as $designStudioThumbnailRenderFailure) {
            $storedLines .= $designStudioThumbnailRenderFailure->toStoredLine();
        }
        $this->appendFailedBoardLines($renderRequest, $storedLines);
        $renderRequest->failedBoards = ($renderRequest->failedBoards ?? '') . $storedLines;
        $this->storeLastError($renderRequest, (string)static::describeRenderFailures($renderRequest->getFailedBoards()));
    }

    /**
     * `failedBoards = CONCAT(COALESCE(failedBoards, ''), :lines)`: one atomic statement.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string $storedLines
     * @return void
     */
    protected function appendFailedBoardLines(DesignStudioThumbnailRenderRequest $renderRequest, string $storedLines): void
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $repoClass::createQueryBuilder()
            ->update($repoClass::BASE_ORM_MODEL, $alias)
            ->set("$alias.failedBoards", "CONCAT(COALESCE($alias.failedBoards, ''), :failedBoardLines)")
            ->where("$alias.id = :renderRequestId")
            ->setParameter('failedBoardLines', $storedLines)
            ->setParameter('renderRequestId', $renderRequest->id)
            ->getQuery()
            ->execute();
    }

    /**
     * Raises the request's renderedThumbnailCount by one with one atomic statement (shards store concurrently).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return void
     */
    protected function countRenderedThumbnail(DesignStudioThumbnailRenderRequest $renderRequest): void
    {
        $this->incrementRenderedThumbnailCount($renderRequest);
        $renderRequest->renderedThumbnailCount = ($renderRequest->renderedThumbnailCount ?? 0) + 1;
    }

    /**
     * `renderedThumbnailCount = COALESCE(renderedThumbnailCount, 0) + 1`: one atomic statement.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return void
     */
    protected function incrementRenderedThumbnailCount(DesignStudioThumbnailRenderRequest $renderRequest): void
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $repoClass::createQueryBuilder()
            ->update($repoClass::BASE_ORM_MODEL, $alias)
            ->set("$alias.renderedThumbnailCount", "COALESCE($alias.renderedThumbnailCount, 0) + 1")
            ->where("$alias.id = :renderRequestId")
            ->setParameter('renderRequestId', $renderRequest->id)
            ->getQuery()
            ->execute();
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return DesignStudioThumbnailRenderFailures the failures all shards recorded so far (read from the database)
     */
    protected function findRenderFailures(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderFailures
    {
        $failedBoards = $this->findRequestColumnValue($renderRequest, 'failedBoards');
        $renderRequest->failedBoards = $failedBoards !== null ? (string)$failedBoards : null;
        return $renderRequest->getFailedBoards();
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @return int pictures all shards of the request stored so far (read from the database)
     */
    protected function findRenderedThumbnailCount(DesignStudioThumbnailRenderRequest $renderRequest): int
    {
        $renderedThumbnailCount = (int)($this->findRequestColumnValue($renderRequest, 'renderedThumbnailCount') ?? 0);
        $renderRequest->renderedThumbnailCount = $renderedThumbnailCount;
        return $renderedThumbnailCount;
    }

    /**
     * A column of the request as stored now: a scalar read, past the identity map (other shards write the row).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string $columnName
     * @return mixed
     */
    protected function findRequestColumnValue(DesignStudioThumbnailRenderRequest $renderRequest, string $columnName): mixed
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $queryBuilder->select("$alias.$columnName")
            ->where("$alias.id = :renderRequestId")
            ->setParameter('renderRequestId', $renderRequest->id)
            ->setMaxResults(1);
        $rows = $queryBuilder->getQuery()->getScalarResult();
        return $rows !== [] ? reset($rows[0]) : null;
    }

    /**
     * The renderer is busy / restarting / unreachable: retry later with exponential backoff, give up after
     * MAX_ATTEMPTS.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param int|null $statusCode
     * @param string $reason
     * @param int $shard
     * @param int $shardCount
     * @return void
     */
    protected function waitForRenderer(
        DesignStudioThumbnailRenderRequest $renderRequest,
        ?int $statusCode,
        string $reason,
        int $shard,
        int $shardCount
    ): void {
        $renderRequest->attempts = ($renderRequest->attempts ?? 0) + 1;
        $this->writeRequestColumns($renderRequest, 'attempts');
        $error = mb_substr(($statusCode !== null ? "HTTP $statusCode " : '') . $reason, 0, 1000);
        if ($renderRequest->attempts >= static::MAX_ATTEMPTS) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_FAILED, $error);
            return;
        }
        $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER, $error);
        $delaySeconds = (int)min(static::MAX_BACKOFF_SECONDS, static::FIRST_BACKOFF_SECONDS * 2 ** ($renderRequest->attempts - 1));
        $this->dispatchRun($renderRequest, $delaySeconds + random_int(0, 10), $shard, $shardCount);
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest an open request
     * @return bool true when the request was not written for STALLED_AFTER_SECONDS; every run, every stored thumbnail
     *              and every retry writes it (writeRequestColumns())
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function isStalled(DesignStudioThumbnailRenderRequest $renderRequest): bool
    {
        $stalledBefore = $this->getCurrentTimestamp() - static::STALLED_AFTER_SECONDS;
        $lastChange = $renderRequest->changeHistory->modifiedTime ?? $renderRequest->changeHistory->createdTime ?? null;
        return $lastChange && $lastChange->getTimestamp() < $stalledBefore;
    }

    /**
     * Writes the named columns of a request together with the modified time of its change history. A partial write
     * maps the change history but writes only the columns it names, so the modified column is named here: without it
     * the request would look unchanged since its creation and isStalled() would count from there.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string ...$propertyNames
     * @return void
     */
    protected function writeRequestColumns(DesignStudioThumbnailRenderRequest $renderRequest, string ...$propertyNames): void
    {
        $modifiedColumn = DesignStudioThumbnailRenderRequest::getChangeHistoryAttribute(true)?->getModifiedColumn();
        $renderRequest->updatePartialIgnoringRights(...$propertyNames, ...($modifiedColumn !== null ? [$modifiedColumn] : []));
        if (isset($renderRequest->changeHistory)) {
            $renderRequest->changeHistory->modifiedTime = DateTime::fromTimestamp($this->getCurrentTimestamp());
        }
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string $status
     * @param string|null $lastError
     * @return void
     */
    protected function setStatus(DesignStudioThumbnailRenderRequest $renderRequest, string $status, ?string $lastError): void
    {
        $renderRequest->status = $status;
        $renderRequest->lastError = $lastError;
        $this->writeRequestColumns($renderRequest, 'status', 'lastError');
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string $lastError
     * @return void
     */
    protected function storeLastError(DesignStudioThumbnailRenderRequest $renderRequest, string $lastError): void
    {
        $renderRequest->lastError = mb_substr($lastError, 0, 1000);
        $this->writeRequestColumns($renderRequest, 'lastError');
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param int $shard
     * @param int $shardCount
     * @param int $renderedBoardCount
     * @param int $startedAt
     * @return void
     */
    protected function logRun(DesignStudioThumbnailRenderRequest $renderRequest, int $shard, int $shardCount, int $renderedBoardCount, int $startedAt): void
    {
        DDDService::instance()->getLogger()->info(sprintf(
            'Design studio render request %d shard %d/%d: %d board(s) in %d s',
            $renderRequest->id,
            $shard,
            $shardCount,
            $renderedBoardCount,
            time() - $startedAt
        ));
    }

    /**
     * @param string|null $themeReference
     * @return string|null the theme's `<style>` element
     */
    protected function getThemeStyle(?string $themeReference): ?string
    {
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        return $designThemeProviderService->getThemeStyleForReference($themeReference);
    }

    /**
     * @return DesignStudioThumbnailsService
     */
    protected function getDesignStudioThumbnailsService(): DesignStudioThumbnailsService
    {
        return DDDService::instance()->getService(DesignStudioThumbnailsService::class);
    }

    /**
     * @return DesignDirectoryReaderService
     */
    protected function getDesignDirectoryReaderService(): DesignDirectoryReaderService
    {
        return DDDService::instance()->getService(DesignDirectoryReaderService::class);
    }
}
