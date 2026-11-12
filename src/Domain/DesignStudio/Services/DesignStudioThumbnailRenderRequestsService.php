<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequests;
use DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsMessage;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequests;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
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

/**
 * Renders the missing thumbnails of a canvas on demand.
 *
 * - Looking at a canvas whose thumbnails are incomplete files a request (at most one open request per canvas and
 *   theme hash; a second look only refreshes the priority boards).
 * - A request runs in shards (config `DesignStudio.renderer.concurrency`), one message each on separate consumers: a
 *   board belongs to shard crc32(boardKey) % n; the next board of a shard is already rendering while the previous
 *   one is stored. A run works for at most RUN_BUDGET_SECONDS, then hands over to a fresh message of its shard.
 * - The boards are read from the checkout of the worker at each run: a board that changed meanwhile is rendered in
 *   its new version.
 * - Renderer busy / restarting / unreachable (401, 404, 429, 502–504, transport): the request waits
 *   (WAITING_FOR_RENDERER) and is retried with exponential backoff; thumbnails stay as they are.
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

    /** @var int Waiting runs in a row before the request fails (backoff caps at MAX_BACKOFF_SECONDS: about 4 hours) */
    public const int MAX_ATTEMPTS = 30;

    /** @var int First retry delay in seconds after a renderer failure (doubles per attempt) */
    public const int FIRST_BACKOFF_SECONDS = 30;

    /** @var int Longest retry delay in seconds */
    public const int MAX_BACKOFF_SECONDS = 600;

    /** @var int Boards rendered at once without a configured concurrency */
    public const int RENDER_CONCURRENCY_DEFAULT = 2;

    /** @var int Upper bound of the concurrency (the renderer answers 429 beyond its queue anyway) */
    public const int RENDER_CONCURRENCY_MAXIMUM = 8;

    /** @var int Most priority board keys kept on a request */
    public const int MAX_PRIORITY_BOARDS = 200;

    /** @var int Seconds without any sign of a render run after which an open request counts as lost and is replaced */
    public const int STALLED_AFTER_SECONDS = 1200;

    /**
     * Files (or refreshes) the render request of a canvas in a look; null when the renderer is off or nothing is
     * missing.
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
        $themeHash = DesignThemeProviderService::computeThemeHash($this->getThemeStyle($themeReference));
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
     * @param int $renderRequestId
     * @param int $shard 0 … $shardCount - 1
     * @param int $shardCount
     * @return void
     * @throws BadRequestException
     * @throws ImagickException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function processRequest(int $renderRequestId, int $shard = 0, int $shardCount = 1): void
    {
        $shardCount = max(1, $shardCount);
        $shard = max(0, min($shardCount - 1, $shard));
        $renderRequest = $this->find($renderRequestId, false);
        if (!$renderRequest || !$renderRequest->isOpen()) {
            return;
        }
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
            // the theme's colours changed since the request was filed: the next look at the canvas files a new one
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_DONE, 'the theme changed since the request was filed');
            return;
        }
        $designStudioThumbnailsService = $this->getDesignStudioThumbnailsService();
        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        $boardsWithoutThumbnail = $designStudioThumbnailsService->findBoardsWithoutThumbnail(
            $designDirectoryCanvas->boards,
            $themeHash,
            $renderRequest->getPriorityBoardKeyList()
        );
        if (!$boardsWithoutThumbnail->count()) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_DONE, null);
            return;
        }
        // this shard's boards: stable by board key (a hand-over or backoff never moves a board to another shard while
        // that one renders it), priority order kept within the shard
        $shardBoards = array_values(array_filter(
            $boardsWithoutThumbnail->getElements(),
            static fn(DesignDirectoryBoard $designDirectoryBoard): bool => static::getShardOfBoard((string)$designDirectoryBoard->boardKey, $shardCount) === $shard
        ));
        if (!$shardBoards) {
            // the other shards finish the rest; the last one closes the request
            return;
        }
        if ($renderRequest->status !== DesignStudioThumbnailRenderRequest::STATUS_RUNNING) {
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_RUNNING, null);
        }
        $startedAt = time();
        $renderedBoardCount = 0;
        // one board at a time per shard; the next one is already rendering while the previous one is stored
        $pendingBoard = null;
        $pendingRenderer = null;
        foreach ($shardBoards as $designDirectoryBoard) {
            $isOverBudget = time() - $startedAt >= static::RUN_BUDGET_SECONDS;
            $startedRenderer = $isOverBudget ? null : $this->startRender($canvasDefinition, $designDirectoryBoard, $themeHash, $themeStyle);
            if ($pendingRenderer !== null) {
                if (!$this->finishRender($renderRequest, $pendingBoard, $pendingRenderer, $shard, $shardCount)) {
                    $startedRenderer?->finishRender();
                    return;
                }
                $renderedBoardCount++;
            }
            $pendingBoard = $startedRenderer ? $designDirectoryBoard : null;
            $pendingRenderer = $startedRenderer;
            if ($isOverBudget) {
                // hand over: a fresh message of this shard continues (and sees priorities refreshed meanwhile)
                $this->logRun($renderRequest, $shard, $shardCount, $renderedBoardCount, $startedAt);
                $this->dispatchRun($renderRequest, 0, $shard, $shardCount);
                return;
            }
        }
        if ($pendingRenderer !== null) {
            if (!$this->finishRender($renderRequest, $pendingBoard, $pendingRenderer, $shard, $shardCount)) {
                return;
            }
            $renderedBoardCount++;
        }
        $this->logRun($renderRequest, $shard, $shardCount, $renderedBoardCount, $startedAt);
        // this shard is through; the request is done when no shard has anything left that can be rendered
        $remainingBoards = $designStudioThumbnailsService->findBoardsWithoutThumbnail($designDirectoryCanvas->boards, $themeHash);
        $hasBoardOfAnotherShard = false;
        foreach ($remainingBoards->getElements() as $remainingBoard) {
            if (static::getShardOfBoard((string)$remainingBoard->boardKey, $shardCount) !== $shard) {
                $hasBoardOfAnotherShard = true;
                break;
            }
        }
        if (!$hasBoardOfAnotherShard) {
            // what is left in this shard could not be rendered (lastError names the board)
            $this->setStatus($renderRequest, DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->lastError ?? null);
        }
    }

    /**
     * @param string $boardKey
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
     * @return void
     */
    public function dispatchRun(DesignStudioThumbnailRenderRequest $renderRequest, int $delaySeconds, int $shard = 0, int $shardCount = 1): void
    {
        $message = new RenderDesignStudioThumbnailsMessage((int)$renderRequest->id, $shard, $shardCount);
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
        $boardContent = $this->getDesignDirectoryReaderService()->readBoardContent($canvasDefinition, (string)$designDirectoryBoard->boardKey);
        // only the version the key names is rendered; a file that changed since the listing waits for the next run
        if ($boardContent === null || DesignDirectoryReaderService::computeContentHash($boardContent) !== $designDirectoryBoard->contentHash) {
            return null;
        }
        /** @var DesignBoardAssetsService $designBoardAssetsService */
        $designBoardAssetsService = DDDService::instance()->getService(DesignBoardAssetsService::class);
        $assetPaths = DesignBoardAssetsService::findReferencedAssetPaths($boardContent);
        if ($assetPaths !== []) {
            // the same for the asset files: the key names their version too
            $assetsHash = $designBoardAssetsService->findCurrentAssetReferences($canvasDefinition, $assetPaths)->computeAssetsHash();
            if ($assetsHash !== $designDirectoryBoard->assetsHash) {
                return null;
            }
            // the renderer loads nothing from the network: the asset files travel inside the board
            $boardContent = $designBoardAssetsService->inlineAssetReferences($boardContent, $canvasDefinition);
        }
        $renderer = new ArgusDesignBoardRenderedThumbnails();
        $renderer->html = $boardContent;
        $renderer->width = max(1, (int)$designDirectoryBoard->width);
        $renderer->height = max(1, (int)$designDirectoryBoard->height);
        $renderer->themeHash = $themeHash;
        $renderer->themeStyle = $themeStyle ?? '';
        $renderer->startRender();
        return $renderer;
    }

    /**
     * Waits for a board's render and stores its thumbnail; false when the renderer is busy / down (the request then
     * waits and this run ends).
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param ArgusDesignBoardRenderedThumbnails $renderer
     * @param int $shard
     * @param int $shardCount
     * @return bool
     * @throws BadRequestException
     * @throws ImagickException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    protected function finishRender(
        DesignStudioThumbnailRenderRequest $renderRequest,
        DesignDirectoryBoard $designDirectoryBoard,
        ArgusDesignBoardRenderedThumbnails $renderer,
        int $shard,
        int $shardCount
    ): bool {
        $renderer->finishRender();
        if ($renderer->failureReason !== null) {
            if ($renderer->isRetryableFailure()) {
                // 429 (renderer queue full), 401 / 404 / 502–504 or transport: back off, nothing lost
                $this->waitForRenderer($renderRequest, $renderer->failureStatusCode, (string)$renderer->failureReason, $shard, $shardCount);
                return false;
            }
            // this board cannot be rendered (e.g. 400 invalid HTML): the others still can
            $renderRequest->lastError = mb_substr("$designDirectoryBoard->boardKey: HTTP $renderer->failureStatusCode $renderer->failureReason", 0, 1000);
            $renderRequest->updatePartialIgnoringRights('lastError');
            return true;
        }
        $this->getDesignStudioThumbnailsService()->storeThumbnail($designDirectoryBoard, $renderer->themeHash, (string)$renderer->png);
        if ($renderRequest->attempts) {
            $renderRequest->attempts = 0;
            $renderRequest->updatePartialIgnoringRights('attempts');
        }
        return true;
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
        $renderRequest->updatePartialIgnoringRights('attempts');
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
     * @return bool true when the request did not change for STALLED_AFTER_SECONDS (longer than the longest backoff
     *              plus one run); every run and every retry writes the request
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function isStalled(DesignStudioThumbnailRenderRequest $renderRequest): bool
    {
        $stalledBefore = time() - static::STALLED_AFTER_SECONDS;
        $lastChange = $renderRequest->changeHistory->modifiedTime ?? $renderRequest->changeHistory->createdTime ?? null;
        return $lastChange && $lastChange->getTimestamp() < $stalledBefore;
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
        $renderRequest->updatePartialIgnoringRights('status', 'lastError');
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
