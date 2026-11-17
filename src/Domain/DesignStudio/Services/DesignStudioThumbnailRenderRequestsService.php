<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderFailure;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderFailures;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequests;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderOutcome;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictures;
use DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailMessage;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\DB\Thumbnails\RenderRequests\DBDesignStudioThumbnailRenderRequests;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * Renders the missing thumbnails of a canvas on demand, one message per picture.
 *
 * - Looking at a canvas whose thumbnails are incomplete files a request (at most one open request per canvas and
 *   theme hash). The request lists its pictures (DesignStudioThumbnailRenderPicture: each fixed board and each device
 *   frame of a responsive board without a thumbnail, the boards on screen first) and dispatches one
 *   RenderDesignStudioThumbnailMessage per picture in that order.
 * - Parallelism is the number of consumer processes of the transport `render_design_studio_thumbnails`: each one
 *   renders one picture at a time. No picture waits for another.
 * - A picture reads its board from the checkout of the worker: a board that changed since the request was filed is
 *   rendered in its current version.
 * - A failed try (renderer busy or unreachable, a render or store that throws or times out) is dispatched again with a
 *   delay (30 s doubling up to 4 minutes) up to MAX_PICTURE_ATTEMPTS tries; then, or at once when the renderer refuses
 *   the picture (e.g. HTTP 400), the picture is FAILED with its reason (failedBoards of the request).
 * - The request counts its pictures: it closes when done, failed and skipped add up to the total — DONE when it stored
 *   at least one picture or ended without an error, FAILED when it ends with an error and stored none.
 *
 * @method DesignStudioThumbnailRenderRequest find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioThumbnailRenderRequests findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioThumbnailRenderRequest getEntityRepoClassInstance()
 * @method DBDesignStudioThumbnailRenderRequests getEntitySetRepoClassInstance()
 */
class DesignStudioThumbnailRenderRequestsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioThumbnailRenderRequest::class;

    /** @var int Tries of one picture before it fails with the reason of the last one */
    public const int MAX_PICTURE_ATTEMPTS = 5;

    /** @var int Delay in seconds before the second try of a picture (doubles per try) */
    public const int FIRST_RETRY_DELAY_SECONDS = 30;

    /** @var int Longest delay before a retry: below STALLED_AFTER_SECONDS, so a waiting request keeps its slot */
    public const int MAX_RETRY_DELAY_SECONDS = 240;

    /** @var int Seconds one picture message may take at most (renderer timeout plus store timeouts and retries) */
    public const int PICTURE_TIME_LIMIT_SECONDS = 300;

    /** @var int Most priority board keys kept on a request */
    public const int MAX_PRIORITY_BOARDS = 200;

    /**
     * @var int Seconds without any write after which an open request counts as lost and is replaced. Every picture
     *          message (a retry too) and every stored thumbnail writes it; longer than the longest retry delay with its
     *          jitter
     */
    public const int STALLED_AFTER_SECONDS = 300;

    /** @var int Pictures named in the request's lastError when it ends with failed pictures */
    public const int FAILED_BOARDS_NAMED_IN_LAST_ERROR = 3;

    /** @var string lastError of a picture whose board is not in the worker's checkout any more */
    public const string BOARD_GONE_ERROR = 'the board is not in this checkout any more';

    /**
     * Files the render request of a canvas in a look and dispatches one message per missing picture (the boards on
     * screen first); an open request is returned as it is. Null when the renderer is off, nothing is missing or the
     * theme provider answers a different style on every call (DesignThemeProviderService::isThemeStyleStable()).
     * A request filed after a failed or incomplete one renders only the pictures still missing: the failed ones (and
     * boards changed since).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string|null $themeReference `<themeId>[:<mode>]`, null = no theme
     * @param string[] $priorityBoardKeys the boards on screen (dispatched first)
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
        $latestRenderRequest = $this->findLatestRequest($canvasDefinition, $themeHash);
        if ($latestRenderRequest && $latestRenderRequest->isOpen() && $this->isStalled($latestRenderRequest)) {
            // its messages are gone (no consumer handled them, or the queue was emptied): close it, a new request takes over
            $this->setStatus($latestRenderRequest, DesignStudioThumbnailRenderRequest::STATUS_FAILED, 'no render run for ' . static::STALLED_AFTER_SECONDS . ' s, replaced by a new request');
            $latestRenderRequest = null;
        }
        if ($latestRenderRequest && $latestRenderRequest->isOpen()) {
            return $latestRenderRequest;
        }
        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        $missingPictures = $designStudioThumbnailsService->findBoardsWithoutThumbnail($designDirectoryCanvas->boards, $themeHash, $priorityBoardKeys);
        if (!$missingPictures->count()) {
            return null;
        }
        $renderRequest = new DesignStudioThumbnailRenderRequest();
        $renderRequest->projectKey = $canvasDefinition->projectKey;
        $renderRequest->canvasKey = $canvasDefinition->canvasKey;
        $renderRequest->themeReference = $themeReference !== null && trim($themeReference) !== '' ? trim($themeReference) : null;
        $renderRequest->themeHash = $themeHash;
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_QUEUED;
        $priorityList = implode("\n", $priorityBoardKeys);
        $renderRequest->priorityBoardKeys = $priorityList !== '' ? $priorityList : null;
        $renderRequest->attempts = 0;
        $renderRequest->renderedThumbnailCount = 0;
        $renderRequest->pictureCount = $missingPictures->count();
        $renderRequest->requestedByAccountId = $requestedBy?->id;
        $renderRequest = $this->update($renderRequest);
        $this->dispatchPictures($renderRequest, $missingPictures);
        return $renderRequest;
    }

    /**
     * Writes the pictures of a request and dispatches one message per picture, in the given order.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignDirectoryBoards $renderTargets fixed boards and device frames, in dispatch order
     * @return void
     */
    protected function dispatchPictures(DesignStudioThumbnailRenderRequest $renderRequest, DesignDirectoryBoards $renderTargets): void
    {
        $designStudioThumbnailRenderPictures = new DesignStudioThumbnailRenderPictures();
        foreach (array_values($renderTargets->getElements()) as $position => $renderTarget) {
            $designStudioThumbnailRenderPicture = DesignStudioThumbnailRenderPicture::forRenderTarget($renderRequest, $renderTarget, $position);
            $designStudioThumbnailRenderPictures->add($designStudioThumbnailRenderPicture);
        }
        $this->getDesignStudioThumbnailRenderPicturesService()->createPictures($designStudioThumbnailRenderPictures);
        foreach ($designStudioThumbnailRenderPictures->getElements() as $designStudioThumbnailRenderPicture) {
            $this->dispatchPictureMessage($renderRequest, (string)$designStudioThumbnailRenderPicture->renderTargetKey, 1, 0);
        }
    }

    /**
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param string $renderTargetKey
     * @param int $attempt
     * @param int $delaySeconds
     * @return void
     */
    protected function dispatchPictureMessage(DesignStudioThumbnailRenderRequest $renderRequest, string $renderTargetKey, int $attempt, int $delaySeconds): void
    {
        $message = new RenderDesignStudioThumbnailMessage((int)$renderRequest->id, $renderTargetKey, $attempt);
        $message->dispatch($delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : []);
    }

    /**
     * A shard run of 0.8 or earlier that was queued at deploy time (deprecated RenderDesignStudioThumbnailsMessage):
     * lists the request's missing pictures and dispatches one message each, once per request — a request that has
     * pictures already is left to them.
     * @param int $renderRequestId
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function convertShardRunToPictureMessages(int $renderRequestId): void
    {
        $renderRequest = $this->find($renderRequestId, false);
        if (!$renderRequest || !$renderRequest->isOpen()) {
            return;
        }
        if ($this->getDesignStudioThumbnailRenderPicturesService()->findPictureCounts($renderRequest)->getTotalCount() > 0) {
            return;
        }
        $this->writeRequestColumns($renderRequest);
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        $canvasDefinition = $designProjectDefinitionsService->getCanvasDefinition((string)$renderRequest->projectKey, (string)$renderRequest->canvasKey);
        if (!$canvasDefinition) {
            $this->closeRequest($renderRequest, null, 'the canvas is not configured in this checkout');
            return;
        }
        $missingPictures = $this->getDesignStudioThumbnailsService()->findBoardsWithoutThumbnail(
            $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition)->boards,
            (string)$renderRequest->themeHash,
            $renderRequest->getPriorityBoardKeyList()
        );
        if (!$missingPictures->count()) {
            $this->closeRequest($renderRequest);
            return;
        }
        $renderRequest->pictureCount = $missingPictures->count();
        $this->writeRequestColumns($renderRequest, 'pictureCount');
        $this->dispatchPictures($renderRequest, $missingPictures);
    }

    /**
     * Renders one picture of a request (the Messenger job). Nothing about this picture is thrown: a failed try is
     * dispatched again with a delay, the last try or a refusal fails the picture with its reason. The request closes
     * when no picture is queued any more.
     * @param int $renderRequestId
     * @param string $renderTargetKey DesignDirectoryBoard::getRenderTargetKey()
     * @param int $attempt this try (1 = first)
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function processPicture(int $renderRequestId, string $renderTargetKey, int $attempt = 1): void
    {
        $renderRequest = $this->find($renderRequestId, false);
        if (!$renderRequest || !$renderRequest->isOpen()) {
            return;
        }
        $designStudioThumbnailRenderPicturesService = $this->getDesignStudioThumbnailRenderPicturesService();
        $designStudioThumbnailRenderPicture = $designStudioThumbnailRenderPicturesService->findPicture($renderRequestId, $renderTargetKey);
        // finished, or a redelivered message of a try that was made already
        if (!$designStudioThumbnailRenderPicture || $designStudioThumbnailRenderPicture->isFinished() || ($designStudioThumbnailRenderPicture->attempts ?? 0) >= $attempt) {
            return;
        }
        // every picture message is a sign of life: isStalled() measures from the last one
        if ($renderRequest->status === DesignStudioThumbnailRenderRequest::STATUS_QUEUED) {
            $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_RUNNING;
            $this->writeRequestColumns($renderRequest, 'status');
        } else {
            $this->writeRequestColumns($renderRequest);
        }
        /** @var DesignProjectDefinitionsService $designProjectDefinitionsService */
        $designProjectDefinitionsService = DDDService::instance()->getService(DesignProjectDefinitionsService::class);
        $canvasDefinition = $designProjectDefinitionsService->getCanvasDefinition((string)$renderRequest->projectKey, (string)$renderRequest->canvasKey);
        if (!$canvasDefinition) {
            $this->closeRequest($renderRequest, null, 'the canvas is not configured in this checkout');
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
        $renderTarget = $this->findRenderTarget($canvasDefinition, $renderTargetKey);
        if ($renderTarget === null) {
            $this->finishPicture($renderRequest, $designStudioThumbnailRenderPicture, DesignStudioThumbnailRenderPicture::STATUS_SKIPPED, static::BOARD_GONE_ERROR);
            return;
        }
        $renderTargets = new DesignDirectoryBoards();
        $renderTargets->add($renderTarget);
        if (!$this->getDesignStudioThumbnailsService()->findBoardsWithoutThumbnail($renderTargets, $themeHash)->count()) {
            // rendered meanwhile (another request of the same look, or a duplicate message)
            $this->finishPicture($renderRequest, $designStudioThumbnailRenderPicture, DesignStudioThumbnailRenderPicture::STATUS_SKIPPED, null);
            return;
        }
        $designStudioThumbnailRenderOutcome = $this->renderPicture($canvasDefinition, $renderTarget, $themeHash, $themeStyle);
        if ($designStudioThumbnailRenderOutcome->result === DesignStudioThumbnailRenderOutcome::RESULT_STORED) {
            if ($this->finishPicture($renderRequest, $designStudioThumbnailRenderPicture, DesignStudioThumbnailRenderPicture::STATUS_DONE, null)
                && $renderRequest->status === DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER) {
                $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_RUNNING;
                $this->writeRequestColumns($renderRequest, 'status');
            }
            return;
        }
        $error = (string)$designStudioThumbnailRenderOutcome->error;
        if ($designStudioThumbnailRenderOutcome->result === DesignStudioThumbnailRenderOutcome::RESULT_REFUSED || $attempt >= static::MAX_PICTURE_ATTEMPTS) {
            $this->finishPicture(
                $renderRequest,
                $designStudioThumbnailRenderPicture,
                DesignStudioThumbnailRenderPicture::STATUS_FAILED,
                $attempt > 1 ? "$error (after $attempt tries)" : $error,
                $renderTarget
            );
            return;
        }
        // try again later; the other pictures go on meanwhile
        $designStudioThumbnailRenderPicturesService->recordFailedTry($designStudioThumbnailRenderPicture, $attempt, $error);
        if ($designStudioThumbnailRenderOutcome->isRendererUnavailable && $renderRequest->status !== DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER) {
            $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER;
            $this->writeRequestColumns($renderRequest, 'status');
        }
        $this->dispatchPictureMessage($renderRequest, $renderTargetKey, $attempt + 1, static::computeRetryDelaySeconds($attempt) + random_int(0, 10));
    }

    /**
     * @param int $failedAttempt the try that failed (1 = first)
     * @return int seconds before the next try: FIRST_RETRY_DELAY_SECONDS doubling, at most MAX_RETRY_DELAY_SECONDS
     */
    public static function computeRetryDelaySeconds(int $failedAttempt): int
    {
        return (int)min(static::MAX_RETRY_DELAY_SECONDS, static::FIRST_RETRY_DELAY_SECONDS * 2 ** max(0, $failedAttempt - 1));
    }

    /**
     * One try of one picture: render and store. Any throwable is this try's failure.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $renderTarget
     * @param string $themeHash
     * @param string|null $themeStyle
     * @return DesignStudioThumbnailRenderOutcome
     */
    protected function renderPicture(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $renderTarget,
        string $themeHash,
        ?string $themeStyle
    ): DesignStudioThumbnailRenderOutcome {
        try {
            $renderer = $this->startRender($canvasDefinition, $renderTarget, $themeHash, $themeStyle);
            if ($renderer === null) {
                return DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_RETRYABLE, 'the board changed while it was read');
            }
            $renderer->finishRender();
        } catch (Throwable $throwable) {
            return DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_RETRYABLE, 'rendering failed: ' . $throwable->getMessage());
        }
        if ($renderer->failureReason !== null) {
            $error = ($renderer->failureStatusCode !== null ? "HTTP $renderer->failureStatusCode " : '') . $renderer->failureReason;
            return $renderer->isRetryableFailure()
                // 429 (renderer queue full), 401 / 404 / 502–504 or transport: the renderer, not the picture
                ? DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_RETRYABLE, $error, true)
                // this picture cannot be rendered (e.g. 400 invalid HTML)
                : DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_REFUSED, $error);
        }
        try {
            $this->getDesignStudioThumbnailsService()->storeThumbnail($renderTarget, $renderer->themeHash, (string)$renderer->png);
        } catch (Throwable $throwable) {
            // the object store failed or timed out, the answer is no image, …
            return DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_RETRYABLE, 'storing the thumbnail failed: ' . $throwable->getMessage());
        }
        return DesignStudioThumbnailRenderOutcome::create(DesignStudioThumbnailRenderOutcome::RESULT_STORED);
    }

    /**
     * Moves a picture to its final status (once: a duplicate message changes nothing), keeps the request's summary
     * (renderedThumbnailCount, failedBoards) and closes the request when no picture is queued any more.
     * @param DesignStudioThumbnailRenderRequest $renderRequest
     * @param DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture
     * @param string $status DesignStudioThumbnailRenderPicture::STATUS_DONE / _FAILED / _SKIPPED
     * @param string|null $error
     * @param DesignDirectoryBoard|null $renderTarget the picture's board in this checkout (names the failed version)
     * @return bool true when this call moved the picture
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function finishPicture(
        DesignStudioThumbnailRenderRequest $renderRequest,
        DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture,
        string $status,
        ?string $error,
        ?DesignDirectoryBoard $renderTarget = null
    ): bool {
        $designStudioThumbnailRenderPicturesService = $this->getDesignStudioThumbnailRenderPicturesService();
        if (!$designStudioThumbnailRenderPicturesService->finishPicture($designStudioThumbnailRenderPicture, $status, $error)) {
            return false;
        }
        if ($status === DesignStudioThumbnailRenderPicture::STATUS_DONE) {
            $this->countRenderedThumbnail($renderRequest);
            $this->writeRequestColumns($renderRequest);
        } elseif ($status === DesignStudioThumbnailRenderPicture::STATUS_FAILED) {
            if ($renderTarget !== null) {
                $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromBoard($renderTarget, (string)$error);
            } else {
                $designStudioThumbnailRenderFailure = new DesignStudioThumbnailRenderFailure();
                $designStudioThumbnailRenderFailure->renderTargetKey = $designStudioThumbnailRenderPicture->renderTargetKey;
                $designStudioThumbnailRenderFailure->boardVersion = '';
                $designStudioThumbnailRenderFailure->error = (string)$error;
            }
            $designStudioThumbnailRenderFailures = new DesignStudioThumbnailRenderFailures();
            $designStudioThumbnailRenderFailures->add($designStudioThumbnailRenderFailure);
            $this->recordRenderFailures($renderRequest, $designStudioThumbnailRenderFailures);
        }
        if ($designStudioThumbnailRenderPicturesService->findPictureCounts($renderRequest)->isComplete()) {
            $this->closeRequest($renderRequest);
        }
        return true;
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $renderTargetKey
     * @return DesignDirectoryBoard|null the fixed board or device frame in the worker's checkout, null when gone
     */
    protected function findRenderTarget(DesignCanvasDefinition $canvasDefinition, string $renderTargetKey): ?DesignDirectoryBoard
    {
        $designDirectoryCanvas = $this->getDesignDirectoryReaderService()->readCanvas($canvasDefinition);
        foreach ($this->getDesignStudioThumbnailsService()->expandRenderTargets($designDirectoryCanvas->boards)->getElements() as $renderTarget) {
            if ($renderTarget->getRenderTargetKey() === $renderTargetKey) {
                return $renderTarget;
            }
        }
        return null;
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
     * @return int the current Unix time (a seam for tests)
     */
    protected function getCurrentTimestamp(): int
    {
        return time();
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

    /**
     * @return DesignStudioThumbnailRenderPicturesService
     */
    protected function getDesignStudioThumbnailRenderPicturesService(): DesignStudioThumbnailRenderPicturesService
    {
        return DDDService::instance()->getService(DesignStudioThumbnailRenderPicturesService::class);
    }
}
