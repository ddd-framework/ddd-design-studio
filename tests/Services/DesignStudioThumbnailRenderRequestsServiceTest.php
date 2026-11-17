<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictureCounts;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPictures;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignProjectDefinitionsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderPicturesService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Symfony\Commands\DesignStudio\DesignStudioThumbnails as DesignStudioThumbnailsCommand;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * One message per picture: no picture waits for another, a failing picture is tried again with a delay and then
 * fails with its reason, and the request closes when its pictures are all done, failed or skipped.
 */
class DesignStudioThumbnailRenderRequestsServiceTest extends TestCase
{
    protected string $projectRootDirectory;

    protected DesignCanvasDefinition $canvasDefinition;

    /** @var bool[] content hash => true for every stored board version */
    protected array $renderedContentHashes = [];

    /** @var int stores that fail (the object store is not reachable) before the next one succeeds */
    protected int $failingThumbnailStoreCount = 0;

    /** @var int existence checks of thumbnail rows that fail (the database is not reachable) before the next one succeeds */
    protected int $failingExistenceCheckCount = 0;

    /** @var string[] board key => exception class thrown by every store of its thumbnail */
    protected array $alwaysFailingStoreExceptionByBoardKey = [];

    /** @var string[] the board keys in the order their thumbnails were stored (or tried) */
    protected array $storeAttemptBoardKeys = [];

    /** @var DesignStudioThumbnailRenderPicture[] "<requestId>|<renderTargetKey>" => picture: the pictures table */
    protected array $storedPictures = [];

    /** @var string[] the board keys listed in canvas.json */
    protected array $listedBoardKeys = [];

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-render-requests-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas', 0777, true);
        $this->canvasDefinition = new DesignCanvasDefinition();
        $this->canvasDefinition->projectKey = 'app';
        $this->canvasDefinition->canvasKey = 'test';
        $this->canvasDefinition->folder = 'design/canvas';
        $this->canvasDefinition->boardPatterns = [];
        $this->canvasDefinition->sectionPatterns = [];
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, new FixedRootDesignDirectoryReaderService($this->projectRootDirectory));
        $testContainer->setTestInstance(DesignProjectDefinitionsService::class, $this->createProjectDefinitionsService());
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, $this->createThumbnailsService());
        $testContainer->setTestInstance(DesignStudioThumbnailRenderPicturesService::class, $this->createPicturesService());
    }

    protected function tearDown(): void
    {
        $testContainer = $this->getTestContainer();
        foreach ([
            DesignDirectoryReaderService::class,
            DesignProjectDefinitionsService::class,
            DesignStudioThumbnailsService::class,
            DesignStudioThumbnailRenderPicturesService::class,
            DesignThemeProviderService::class,
        ] as $serviceClass) {
            $testContainer->setTestInstance($serviceClass, null);
        }
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    protected function getTestContainer(): TestContainer
    {
        return (new ReflectionProperty(DDDBundle::class, 'defaultContainer'))->getValue();
    }

    protected function createProjectDefinitionsService(): DesignProjectDefinitionsService
    {
        return new class($this->canvasDefinition) extends DesignProjectDefinitionsService {
            public function __construct(protected DesignCanvasDefinition $testCanvasDefinition)
            {
            }

            public function getCanvasDefinition(string $projectKey, string $canvasKey): ?DesignCanvasDefinition
            {
                return $this->testCanvasDefinition;
            }
        };
    }

    protected function createThumbnailsService(): DesignStudioThumbnailsService
    {
        $renderedContentHashes = &$this->renderedContentHashes;
        $failingThumbnailStoreCount = &$this->failingThumbnailStoreCount;
        $alwaysFailingStoreExceptionByBoardKey = &$this->alwaysFailingStoreExceptionByBoardKey;
        $storeAttemptBoardKeys = &$this->storeAttemptBoardKeys;
        $failingExistenceCheckCount = &$this->failingExistenceCheckCount;
        return new class($renderedContentHashes, $failingThumbnailStoreCount, $alwaysFailingStoreExceptionByBoardKey, $storeAttemptBoardKeys, $failingExistenceCheckCount) extends DesignStudioThumbnailsService {
            public function __construct(
                protected array &$testRenderedContentHashes,
                protected int &$testFailingThumbnailStoreCount,
                protected array &$testAlwaysFailingStoreExceptionByBoardKey,
                protected array &$testStoreAttemptBoardKeys,
                protected int &$testFailingExistenceCheckCount
            ) {
            }

            public function isRendererEnabled(): bool
            {
                return true;
            }

            public function storeThumbnail(DesignDirectoryBoard $designDirectoryBoard, string $themeHash, string $png): ?DesignStudioThumbnail
            {
                $this->testStoreAttemptBoardKeys[] = (string)$designDirectoryBoard->boardKey;
                $exceptionClass = $this->testAlwaysFailingStoreExceptionByBoardKey[(string)$designDirectoryBoard->boardKey] ?? null;
                if ($exceptionClass !== null) {
                    // a hanging upload ends with the store's timeout (timeoutSeconds / maxRetries of the thumbnail store)
                    throw new $exceptionClass($exceptionClass === BadRequestException::class
                        ? "Thumbnail of $designDirectoryBoard->boardKey: not an image"
                        : ($this->testAlwaysFailingStoreExceptionByBoardKey['message'] ?? 'Object storage "test": put failed: Idle timeout reached'));
                }
                if ($this->testFailingThumbnailStoreCount > 0) {
                    $this->testFailingThumbnailStoreCount--;
                    throw new InternalErrorException('Object storage "test": put failed: Could not contact remote server');
                }
                $this->testRenderedContentHashes[(string)$designDirectoryBoard->contentHash] = true;
                return null;
            }

            public function findForBoards(DesignDirectoryBoards $designDirectoryBoards, string $themeHash): DesignStudioThumbnails
            {
                if ($this->testFailingExistenceCheckCount > 0) {
                    $this->testFailingExistenceCheckCount--;
                    throw new InternalErrorException('SQLSTATE[08006] server closed the connection unexpectedly');
                }
                $designStudioThumbnails = new DesignStudioThumbnails();
                foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
                    if (isset($this->testRenderedContentHashes[(string)$designDirectoryBoard->contentHash])) {
                        $designStudioThumbnail = new DesignStudioThumbnail();
                        $designStudioThumbnail->id = count($designStudioThumbnails->getElements()) + 1;
                        $designStudioThumbnail->thumbnailKey = $this->computeThumbnailKey((string)$designDirectoryBoard->contentHash, $themeHash, $designDirectoryBoard->assetsHash);
                        $designStudioThumbnails->add($designStudioThumbnail);
                    }
                }
                return $designStudioThumbnails;
            }
        };
    }

    /** The pictures table in memory, with the compare-and-set of the real service. */
    protected function createPicturesService(): DesignStudioThumbnailRenderPicturesService
    {
        $storedPictures = &$this->storedPictures;
        return new class($storedPictures) extends DesignStudioThumbnailRenderPicturesService {
            public int $testCreatedTimestamp = 1000000;

            public function __construct(protected array &$testStoredPictures)
            {
            }

            public function findPictures(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderPictures
            {
                $designStudioThumbnailRenderPictures = new DesignStudioThumbnailRenderPictures();
                foreach ($this->testStoredPictures as $storedPicture) {
                    if ($storedPicture->renderRequestId === $renderRequest->id) {
                        $pictureCopy = clone $storedPicture;
                        $designStudioThumbnailRenderPictures->add($pictureCopy);
                    }
                }
                return $designStudioThumbnailRenderPictures;
            }

            public function findQueuedPictures(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderPictures
            {
                $queuedPictures = new DesignStudioThumbnailRenderPictures();
                foreach ($this->findPictures($renderRequest)->getElements() as $designStudioThumbnailRenderPicture) {
                    if ($designStudioThumbnailRenderPicture->status === DesignStudioThumbnailRenderPicture::STATUS_QUEUED) {
                        $queuedPictures->add($designStudioThumbnailRenderPicture);
                    }
                }
                return $queuedPictures;
            }

            public function recordRedispatch(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture, string $reason, DateTime $redispatchedAt): void
            {
                $storedPicture = $this->testStoredPictures[$designStudioThumbnailRenderPicture->renderRequestId . '|' . $designStudioThumbnailRenderPicture->renderTargetKey];
                $storedPicture->lastError = $designStudioThumbnailRenderPicture->lastError = $reason;
                $storedPicture->nextAttemptAt = $designStudioThumbnailRenderPicture->nextAttemptAt = $redispatchedAt;
            }

            public function createPictures(DesignStudioThumbnailRenderPictures $designStudioThumbnailRenderPictures): void
            {
                foreach ($designStudioThumbnailRenderPictures->getElements() as $designStudioThumbnailRenderPicture) {
                    $key = $designStudioThumbnailRenderPicture->renderRequestId . '|' . $designStudioThumbnailRenderPicture->renderTargetKey;
                    if (!isset($this->testStoredPictures[$key])) {
                        $designStudioThumbnailRenderPicture->id = count($this->testStoredPictures) + 1;
                        $designStudioThumbnailRenderPicture->changeHistory = new ChangeHistory();
                        $designStudioThumbnailRenderPicture->changeHistory->createdTime = DateTime::fromTimestamp($this->testCreatedTimestamp);
                        $this->testStoredPictures[$key] = $designStudioThumbnailRenderPicture;
                    }
                }
            }

            public function findPicture(int $renderRequestId, string $renderTargetKey): ?DesignStudioThumbnailRenderPicture
            {
                $designStudioThumbnailRenderPicture = $this->testStoredPictures[$renderRequestId . '|' . $renderTargetKey] ?? null;
                return $designStudioThumbnailRenderPicture ? clone $designStudioThumbnailRenderPicture : null;
            }

            public function finishPicture(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture, string $status, ?string $lastError): bool
            {
                $storedPicture = $this->testStoredPictures[$designStudioThumbnailRenderPicture->renderRequestId . '|' . $designStudioThumbnailRenderPicture->renderTargetKey];
                if ($storedPicture->status !== DesignStudioThumbnailRenderPicture::STATUS_QUEUED) {
                    return false;
                }
                $storedPicture->status = $designStudioThumbnailRenderPicture->status = $status;
                $storedPicture->lastError = $designStudioThumbnailRenderPicture->lastError = $lastError;
                return true;
            }

            public function recordFailedTry(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture, int $attempts, string $lastError, DateTime $nextAttemptAt): void
            {
                $storedPicture = $this->testStoredPictures[$designStudioThumbnailRenderPicture->renderRequestId . '|' . $designStudioThumbnailRenderPicture->renderTargetKey];
                $storedPicture->attempts = $designStudioThumbnailRenderPicture->attempts = $attempts;
                $storedPicture->lastError = $designStudioThumbnailRenderPicture->lastError = $lastError;
                $storedPicture->nextAttemptAt = $designStudioThumbnailRenderPicture->nextAttemptAt = $nextAttemptAt;
            }

            public function findPictureCounts(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderPictureCounts
            {
                $designStudioThumbnailRenderPictureCounts = new DesignStudioThumbnailRenderPictureCounts();
                foreach ($this->testStoredPictures as $storedPicture) {
                    if ($storedPicture->renderRequestId !== $renderRequest->id) {
                        continue;
                    }
                    match ($storedPicture->status) {
                        DesignStudioThumbnailRenderPicture::STATUS_DONE => $designStudioThumbnailRenderPictureCounts->doneCount++,
                        DesignStudioThumbnailRenderPicture::STATUS_FAILED => $designStudioThumbnailRenderPictureCounts->failedCount++,
                        DesignStudioThumbnailRenderPicture::STATUS_SKIPPED => $designStudioThumbnailRenderPictureCounts->skippedCount++,
                        default => $designStudioThumbnailRenderPictureCounts->queuedCount++,
                    };
                }
                return $designStudioThumbnailRenderPictureCounts;
            }
        };
    }

    /**
     * A render requests service without database, message bus and renderer: requests live in memory, dispatched
     * messages are recorded and run on demand, a "render" answers a picture unless the test makes it fail.
     */
    protected function createService(): DesignStudioThumbnailRenderRequestsService
    {
        return new class extends DesignStudioThumbnailRenderRequestsService {
            /** @var DesignStudioThumbnailRenderRequest[] id => request */
            public array $renderRequests = [];

            /** @var array[] the dispatched picture messages: [renderRequestId, renderTargetKey, attempt, delaySeconds] */
            public array $dispatchedMessages = [];

            public int $currentTimestamp = 1000000;

            /** true: the theme style comes from the registered DesignThemeProviderService */
            public bool $readsTheThemeProvider = false;

            /** @var int[] board key => HTTP status the renderer answers for it (400: refused, 429: busy) */
            public array $rendererFailureStatusByBoardKey = [];

            public function find($entityId, bool $useEntityRegistrCache = true): ?DesignStudioThumbnailRenderRequest
            {
                return $this->renderRequests[(int)$entityId] ?? null;
            }

            public function findLatestRequest(DesignCanvasDefinition $canvasDefinition, string $themeHash): ?DesignStudioThumbnailRenderRequest
            {
                return $this->renderRequests ? $this->renderRequests[max(array_keys($this->renderRequests))] : null;
            }

            public function update(DefaultObject $entity, int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): DefaultObject
            {
                /** @var DesignStudioThumbnailRenderRequest $entity */
                $entity->id ??= count($this->renderRequests) + 1;
                $entity->changeHistory = new ChangeHistory();
                $entity->changeHistory->createdTime = DateTime::fromTimestamp($this->currentTimestamp);
                $entity->failedBoards = null;
                $this->renderRequests[$entity->id] = $entity;
                return $entity;
            }

            protected function dispatchPictureMessage(DesignStudioThumbnailRenderRequest $renderRequest, string $renderTargetKey, int $attempt, int $delaySeconds): void
            {
                $this->dispatchedMessages[] = [(int)$renderRequest->id, $renderTargetKey, $attempt, $delaySeconds];
            }

            /** Runs the oldest dispatched message (as a consumer would, the delay elapsed) and returns it. */
            public function runNextMessage(): array
            {
                $dispatchedMessage = array_shift($this->dispatchedMessages);
                [$renderRequestId, $renderTargetKey, $attempt] = $dispatchedMessage;
                $this->processPicture($renderRequestId, $renderTargetKey, $attempt);
                return $dispatchedMessage;
            }

            /** @return array[] every message run, until none is left */
            public function runAllMessages(): array
            {
                $runMessages = [];
                while ($this->dispatchedMessages) {
                    $runMessages[] = $this->runNextMessage();
                }
                return $runMessages;
            }

            protected function getThemeStyle(?string $themeReference): ?string
            {
                return $this->readsTheThemeProvider ? parent::getThemeStyle($themeReference) : null;
            }

            protected function setStatus(DesignStudioThumbnailRenderRequest $renderRequest, string $status, ?string $lastError): void
            {
                $renderRequest->status = $status;
                $renderRequest->lastError = $lastError;
                $this->writeRequestColumns($renderRequest, 'status', 'lastError');
            }

            protected function writeRequestColumns(DesignStudioThumbnailRenderRequest $renderRequest, string ...$propertyNames): void
            {
                $renderRequest->changeHistory->modifiedTime = DateTime::fromTimestamp($this->currentTimestamp);
            }

            protected function findRequestColumnValue(DesignStudioThumbnailRenderRequest $renderRequest, string $columnName): mixed
            {
                return $renderRequest->$columnName ?? null;
            }

            protected function appendFailedBoardLines(DesignStudioThumbnailRenderRequest $renderRequest, string $storedLines): void
            {
            }

            protected function incrementRenderedThumbnailCount(DesignStudioThumbnailRenderRequest $renderRequest): void
            {
            }

            protected function getCurrentTimestamp(): int
            {
                return $this->currentTimestamp;
            }

            protected function startRender(
                DesignCanvasDefinition $canvasDefinition,
                DesignDirectoryBoard $designDirectoryBoard,
                string $themeHash,
                ?string $themeStyle
            ): ?ArgusDesignBoardRenderedThumbnails {
                if ($this->readBoardContentForRender($canvasDefinition, $designDirectoryBoard) === null) {
                    return null;
                }
                $renderer = (new ReflectionClass(ArgusDesignBoardRenderedThumbnails::class))->newInstanceWithoutConstructor();
                $renderer->themeHash = $themeHash;
                $failureStatus = $this->rendererFailureStatusByBoardKey[(string)$designDirectoryBoard->boardKey] ?? null;
                if ($failureStatus !== null) {
                    $renderer->failureStatusCode = $failureStatus;
                    $renderer->failureReason = $failureStatus === 400 ? 'invalid HTML' : 'renderer queue full';
                }
                return $renderer;
            }
        };
    }

    protected function writeBoard(string $boardKey, string $content): void
    {
        $canvasFolder = $this->projectRootDirectory . '/design/canvas';
        file_put_contents($canvasFolder . '/' . $boardKey, $content);
        if (!in_array($boardKey, $this->listedBoardKeys, true)) {
            $this->listedBoardKeys[] = $boardKey;
            $boards = [];
            foreach ($this->listedBoardKeys as $index => $listedBoardKey) {
                $boards[$listedBoardKey] = ['x' => $index * 500, 'y' => 0, 'w' => 390, 'h' => 844];
            }
            file_put_contents($canvasFolder . '/canvas.json', json_encode(['boards' => $boards]));
        }
        clearstatcache();
    }

    /** @param string[] $boardKeys */
    protected function writeBoards(array $boardKeys): void
    {
        foreach ($boardKeys as $boardKey) {
            $this->writeBoard($boardKey, "<p>$boardKey</p>");
        }
    }

    protected function fileRequest(DesignStudioThumbnailRenderRequestsService $service, array $priorityBoardKeys = []): DesignStudioThumbnailRenderRequest
    {
        $renderRequest = $service->requestRendering($this->canvasDefinition, null, $priorityBoardKeys, null);
        $this->assertNotNull($renderRequest);
        return $renderRequest;
    }

    public function testEveryPictureIsAMessageOfItsOwnBoardsOnScreenFirst(): void
    {
        $this->writeBoards(['a.html', 'b.html', 'c.html']);
        $service = $this->createService();

        $renderRequest = $this->fileRequest($service, ['c.html']);

        $this->assertSame(3, $renderRequest->pictureCount);
        $this->assertSame(
            [[1, 'c.html', 1, 0], [1, 'a.html', 1, 0], [1, 'b.html', 1, 0]],
            $service->dispatchedMessages
        );
        $this->assertCount(3, $this->storedPictures);
        $service->runAllMessages();
        $this->assertSame(['c.html', 'a.html', 'b.html'], $this->storeAttemptBoardKeys);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertNull($renderRequest->lastError);
        $this->assertSame(3, $renderRequest->renderedThumbnailCount);
    }

    /**
     * @return string[][] the exception a store throws for one picture: a store timeout (a hanging upload), and an
     *                    answer that is no image
     */
    public static function provideFailingStoreExceptions(): array
    {
        return [
            'store timeout' => [InternalErrorException::class, 'storing the thumbnail failed: Object storage "test": put failed: Idle timeout reached'],
            'existence check of the full picture' => [InternalErrorException::class, 'storing the thumbnail failed: Object storage "test": check of design-studio/thumbnails/x.png failed: Could not contact remote server'],
            'not an image' => [BadRequestException::class, 'storing the thumbnail failed: Thumbnail of b.html: not an image'],
        ];
    }

    /**
     * @dataProvider provideFailingStoreExceptions
     */
    public function testOneFailingPictureDoesNotHoldBackTheOthersIsRetriedWithDelayAndFailsWithItsReason(string $exceptionClass, string $expectedError): void
    {
        $this->writeBoards(['a.html', 'b.html', 'c.html', 'd.html']);
        $this->alwaysFailingStoreExceptionByBoardKey = ['b.html' => $exceptionClass];
        if (str_contains($expectedError, 'check of')) {
            $this->alwaysFailingStoreExceptionByBoardKey['message'] = substr($expectedError, strlen('storing the thumbnail failed: '));
        }
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service, ['b.html']);

        $runMessages = $service->runAllMessages();

        // the failing picture goes first; every other picture is stored before its first retry
        $this->assertSame(['b.html', 'a.html', 'c.html', 'd.html'], array_slice($this->storeAttemptBoardKeys, 0, 4));
        $this->assertCount(3, $this->renderedContentHashes);
        $retries = array_values(array_filter($runMessages, static fn(array $runMessage): bool => $runMessage[2] > 1));
        $this->assertCount(DesignStudioThumbnailRenderRequestsService::MAX_PICTURE_ATTEMPTS - 1, $retries);
        foreach ($retries as $index => [, $renderTargetKey, $attempt, $delaySeconds]) {
            $this->assertSame('b.html', $renderTargetKey);
            $this->assertSame($index + 2, $attempt);
            $expectedDelaySeconds = DesignStudioThumbnailRenderRequestsService::computeRetryDelaySeconds($index + 1);
            $this->assertGreaterThanOrEqual($expectedDelaySeconds, $delaySeconds);
            $this->assertLessThanOrEqual($expectedDelaySeconds + 10, $delaySeconds);
        }
        $this->assertSame([30, 60, 120, 240], array_map(DesignStudioThumbnailRenderRequestsService::computeRetryDelaySeconds(...), [1, 2, 3, 4]));
        // the request ends with "1 failed" and the reason of the picture
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame(3, $renderRequest->renderedThumbnailCount);
        $failedBoards = $renderRequest->getFailedBoards();
        $this->assertCount(1, $failedBoards->getElements());
        $this->assertSame('b.html', $failedBoards->first()->renderTargetKey);
        $this->assertSame($expectedError . ' (after 5 tries)', $failedBoards->first()->error);
        $this->assertSame('1 picture(s) failed: b.html: ' . $expectedError . ' (after 5 tries)', $renderRequest->lastError);
        $this->assertSame(DesignStudioThumbnailRenderPicture::STATUS_FAILED, $this->storedPictures['1|b.html']->status);
    }

    public function testAPictureThatFailsOnceIsStoredOnItsRetry(): void
    {
        $this->writeBoards(['home.html']);
        $this->failingThumbnailStoreCount = 1;
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);

        $service->runNextMessage();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);
        $this->assertSame(1, $this->storedPictures['1|home.html']->attempts);
        $this->assertStringContainsString('Could not contact remote server', (string)$this->storedPictures['1|home.html']->lastError);
        $this->assertSame('home.html', $service->dispatchedMessages[0][1]);
        $this->assertSame(2, $service->dispatchedMessages[0][2]);

        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertNull($renderRequest->lastError);
        $this->assertSame(1, $renderRequest->renderedThumbnailCount);
    }

    public function testARequestWhosePicturesAllFailEndsFailedWithTheReasonPerPicture(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $this->alwaysFailingStoreExceptionByBoardKey = ['a.html' => InternalErrorException::class, 'b.html' => InternalErrorException::class];
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);

        $service->runAllMessages();

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_FAILED, $renderRequest->status);
        $this->assertCount(2, $renderRequest->getFailedBoards()->getElements());
        $this->assertStringStartsWith('2 picture(s) failed: ', (string)$renderRequest->lastError);
    }

    public function testAPictureTheRendererRefusesFailsAtOnce(): void
    {
        $this->writeBoards(['a.html', 'broken.html']);
        $service = $this->createService();
        $service->rendererFailureStatusByBoardKey = ['broken.html' => 400];
        $renderRequest = $this->fileRequest($service);

        $runMessages = $service->runAllMessages();

        $this->assertCount(2, $runMessages, 'no retry for a refused picture');
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame('HTTP 400 invalid HTML', $renderRequest->getFailedBoards()->first()->error);
    }

    public function testABusyRendererMakesTheRequestWaitAndThePictureIsRetried(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $service->rendererFailureStatusByBoardKey = ['b.html' => 429];
        $renderRequest = $this->fileRequest($service);

        $service->runNextMessage();
        $service->runNextMessage();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER, $renderRequest->status);
        $this->assertSame([1, 'b.html', 2], array_slice($service->dispatchedMessages[0], 0, 3));

        $service->rendererFailureStatusByBoardKey = [];
        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame(2, $renderRequest->renderedThumbnailCount);
    }

    public function testARetryFromTheCanvasOrConsoleDispatchesOnlyThePicturesStillMissing(): void
    {
        $this->writeBoards(['a.html', 'b.html', 'c.html']);
        $this->alwaysFailingStoreExceptionByBoardKey = ['b.html' => InternalErrorException::class];
        $service = $this->createService();
        $this->fileRequest($service);
        $service->runAllMessages();

        $this->alwaysFailingStoreExceptionByBoardKey = [];
        $retryRequest = $this->fileRequest($service);

        $this->assertSame(2, $retryRequest->id);
        $this->assertSame(1, $retryRequest->pictureCount);
        $this->assertSame([[2, 'b.html', 1, 0]], $service->dispatchedMessages);
        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $retryRequest->status);
    }

    public function testADuplicateMessageCountsThePictureOnce(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        $service->dispatchedMessages[] = $service->dispatchedMessages[0];

        $service->runAllMessages();

        $this->assertSame(2, $renderRequest->renderedThumbnailCount);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
    }

    public function testAShardMessageQueuedBeforeTheUpgradeIsConvertedIntoPictureMessagesOnce(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = new DesignStudioThumbnailRenderRequest();
        $renderRequest->projectKey = 'app';
        $renderRequest->canvasKey = 'test';
        $renderRequest->themeReference = null;
        $renderRequest->themeHash = DesignThemeProviderService::computeThemeHash(null);
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_RUNNING;
        $renderRequest->priorityBoardKeys = "b.html";
        $renderRequest->renderedThumbnailCount = 0;
        $service->update($renderRequest);

        // two shards of the old request: the first converts, the second finds the pictures there
        $service->convertShardRunToPictureMessages(1);
        $service->convertShardRunToPictureMessages(1);

        $this->assertSame([[1, 'b.html', 1, 0], [1, 'a.html', 1, 0]], $service->dispatchedMessages);
        $this->assertSame(2, $renderRequest->pictureCount);
        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
    }

    public function testAThemeThatReturnsADifferentStyleOnEachCallFailsTheRequestWithTheReason(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        $this->getTestContainer()->setTestInstance(DesignThemeProviderService::class, new class extends DesignThemeProviderService {
            protected int $callCount = 0;

            public function getThemeStyle(string $themeId, string $mode): ?string
            {
                // an embedded image with its encode time: another style on every call
                return '<style data-design-theme>:root { --logo: url(data:image/png;base64,' . (++$this->callCount) . ') }</style>';
            }
        });
        $renderRequest->themeReference = 'brand:1';
        $service->readsTheThemeProvider = true;

        $service->runAllMessages();

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_FAILED, $renderRequest->status);
        $this->assertSame(DesignThemeProviderService::THEME_STYLE_UNSTABLE_ERROR, $renderRequest->lastError);
        $this->assertSame([], $this->renderedContentHashes);
        // no new request is filed for such a theme
        $this->assertNull($service->requestRendering($this->canvasDefinition, 'brand:1', [], null));
    }

    public function testAThemeThatChangedSinceTheRequestFailsItWhenNothingWasRendered(): void
    {
        $this->writeBoards(['home.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        $renderRequest->themeHash = hash('sha256', 'an older style');

        $service->runAllMessages();

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_FAILED, $renderRequest->status);
        $this->assertSame(DesignThemeProviderService::THEME_CHANGED_ERROR, $renderRequest->lastError);
    }

    public function testAPictureWhoseBoardIsGoneIsSkippedAndTheRequestCloses(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        unlink($this->projectRootDirectory . '/design/canvas/b.html');
        clearstatcache();

        $service->runAllMessages();

        $this->assertSame(DesignStudioThumbnailRenderPicture::STATUS_SKIPPED, $this->storedPictures['1|b.html']->status);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
    }

    public function testEveryPictureMessageWritesTheRequestAndStallingCountsFromTheLastWrite(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        $service->currentTimestamp += 3600;
        $this->assertTrue($service->isStalled($renderRequest), 'never written for an hour');

        $service->runNextMessage();

        $this->assertSame($service->currentTimestamp, $renderRequest->changeHistory->modifiedTime->getTimestamp());
        $this->assertFalse($service->isStalled($renderRequest));
        $this->assertLessThan(
            DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS - 10,
            DesignStudioThumbnailRenderRequestsService::MAX_RETRY_DELAY_SECONDS,
            'a picture waiting for its retry (with jitter) keeps the request alive'
        );
    }

    public function testARetryKeepsItsTryNumberAndTheTimeOfTheNextTryOnThePicture(): void
    {
        $this->writeBoards(['home.html']);
        $this->failingThumbnailStoreCount = 1;
        $service = $this->createService();
        $this->fileRequest($service);

        $service->runNextMessage();

        $storedPicture = $this->storedPictures['1|home.html'];
        $this->assertSame(DesignStudioThumbnailRenderPicture::STATUS_QUEUED, $storedPicture->status);
        $this->assertSame(1, $storedPicture->attempts);
        $delaySeconds = $service->dispatchedMessages[0][3];
        $this->assertSame($service->currentTimestamp + $delaySeconds, $storedPicture->nextAttemptAt->getTimestamp());
        $this->assertTrue($storedPicture->isWaitingForRetry($service->currentTimestamp));
        $this->assertFalse($storedPicture->isWaitingForRetry($service->currentTimestamp + $delaySeconds));
        $pictures = $this->getTestContainer()->get(DesignStudioThumbnailRenderPicturesService::class)->findPictures($service->renderRequests[1]);
        $this->assertSame(1, $pictures->getPicturesWaitingForRetry($service->currentTimestamp)->count());
        $this->assertSame($storedPicture->nextAttemptAt->getTimestamp(), $pictures->getLatestNextAttemptAt()->getTimestamp());
        $this->assertSame(
            'queued: home.html — try 2 of 5 at ' . $storedPicture->nextAttemptAt . ' — storing the thumbnail failed: Object storage "test": put failed: Could not contact remote server',
            DesignStudioThumbnailsCommand::describePicture($storedPicture)
        );
    }

    public function testAThrowFromTheExistenceCheckIsThatPicturesFailedTryAndRetriedWithTheDelay(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        $this->failingExistenceCheckCount = 1;

        $service->runNextMessage();

        $this->assertSame(1, $this->storedPictures['1|a.html']->attempts);
        $this->assertStringStartsWith('checking the picture failed: SQLSTATE', (string)$this->storedPictures['1|a.html']->lastError);
        $this->assertNotNull($this->storedPictures['1|a.html']->nextAttemptAt);
        $retryMessage = end($service->dispatchedMessages);
        $this->assertSame(['a.html', 2], [$retryMessage[1], $retryMessage[2]]);
        $this->assertGreaterThanOrEqual(DesignStudioThumbnailRenderRequestsService::FIRST_RETRY_DELAY_SECONDS, $retryMessage[3]);

        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame(2, $renderRequest->renderedThumbnailCount);
    }

    public function testAPictureNoConsumerTookIsDispatchedAgainOnceAfterTheThreshold(): void
    {
        $this->writeBoards(['a.html', 'b.html']);
        $service = $this->createService();
        $renderRequest = $this->fileRequest($service);
        // the first message is consumed and fails once; the second one is lost (no consumer read it)
        $this->failingThumbnailStoreCount = 1;
        $service->runNextMessage();
        $service->dispatchedMessages = [];

        $service->currentTimestamp += DesignStudioThumbnailRenderRequestsService::UNCONSUMED_PICTURE_AFTER_SECONDS - 1;
        $this->assertSame(0, $service->redispatchUnconsumedPictures($renderRequest), 'not before the threshold');

        $service->currentTimestamp += 1;
        $this->assertSame(1, $service->redispatchUnconsumedPictures($renderRequest));
        $this->assertSame([[1, 'b.html', 1, 0]], $service->dispatchedMessages, 'only the picture without a try');
        $this->assertSame(DesignStudioThumbnailRenderRequestsService::UNCONSUMED_PICTURE_ERROR, $this->storedPictures['1|b.html']->lastError);
        $this->assertSame(0, $service->redispatchUnconsumedPictures($renderRequest), 'once');
        $this->assertSame(1, $service->requestRendering($this->canvasDefinition, null, [], null)->id, 'the open request is returned, nothing dispatched again');
        $this->assertCount(1, $service->dispatchedMessages);

        $service->runAllMessages();
        $this->assertSame(DesignStudioThumbnailRenderPicture::STATUS_DONE, $this->storedPictures['1|b.html']->status);
    }

    public function testTheThresholdOfAnUnconsumedPictureIsBelowTheRequestStall(): void
    {
        $this->assertLessThan(DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS, DesignStudioThumbnailRenderRequestsService::UNCONSUMED_PICTURE_AFTER_SECONDS);
        $failedPicture = new DesignStudioThumbnailRenderPicture();
        $failedPicture->renderTargetKey = 'a.html';
        $failedPicture->status = DesignStudioThumbnailRenderPicture::STATUS_FAILED;
        $failedPicture->attempts = 5;
        $failedPicture->lastError = 'HTTP 503 down (after 5 tries)';
        $this->assertSame('failed: a.html — after 5 tries — HTTP 503 down (after 5 tries)', DesignStudioThumbnailsCommand::describePicture($failedPicture));
        $queuedPicture = new DesignStudioThumbnailRenderPicture();
        $queuedPicture->renderTargetKey = 'b.html';
        $queuedPicture->status = DesignStudioThumbnailRenderPicture::STATUS_QUEUED;
        $queuedPicture->attempts = 0;
        $queuedPicture->nextAttemptAt = null;
        $this->assertSame('queued: b.html — try 1 of 5, not taken by a consumer yet', DesignStudioThumbnailsCommand::describePicture($queuedPicture));
    }

    public function testTheRendererTimeoutBoundsOnePicture(): void
    {
        $this->assertSame(60.0, ArgusDesignBoardRenderedThumbnails::getConfiguredTimeoutSeconds());
        $this->assertLessThanOrEqual(
            DesignStudioThumbnailRenderRequestsService::PICTURE_TIME_LIMIT_SECONDS,
            ArgusDesignBoardRenderedThumbnails::getConfiguredTimeoutSeconds() + ArgusDesignBoardRenderedThumbnails::WRITE_REQUESTS_SECONDS
        );
    }
}
