<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignProjectDefinitionsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Tests\DesignStudio\Support\FixedRootDesignDirectoryReaderService;
use DDD\Tests\DesignStudio\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A render request never stays open without a run: a board that changes between the listing and its render is read
 * again in a follow-up run, a shard that is through watches the other shards, and the request closes once nothing
 * renderable is missing.
 */
class DesignStudioThumbnailRenderRequestsServiceTest extends TestCase
{
    protected string $projectRootDirectory;

    protected DesignCanvasDefinition $canvasDefinition;

    /** @var string[] content hash => true for every rendered board version */
    protected array $renderedContentHashes = [];

    /** @var int stores of a thumbnail that fail (the object store is not reachable) before the next one succeeds */
    protected int $failingThumbnailStoreCount = 0;

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
        $this->renderedContentHashes = [];
        $this->listedBoardKeys = [];
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, new FixedRootDesignDirectoryReaderService($this->projectRootDirectory));
        $testContainer->setTestInstance(DesignProjectDefinitionsService::class, $this->createProjectDefinitionsService());
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, $this->createThumbnailsService());
    }

    protected function tearDown(): void
    {
        $testContainer = $this->getTestContainer();
        $testContainer->setTestInstance(DesignDirectoryReaderService::class, null);
        $testContainer->setTestInstance(DesignProjectDefinitionsService::class, null);
        $testContainer->setTestInstance(DesignStudioThumbnailsService::class, null);
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
        return new class($renderedContentHashes, $failingThumbnailStoreCount) extends DesignStudioThumbnailsService {
            public function __construct(protected array &$testRenderedContentHashes, protected int &$testFailingThumbnailStoreCount)
            {
            }

            public function storeThumbnail(DesignDirectoryBoard $designDirectoryBoard, string $themeHash, string $png): ?DesignStudioThumbnail
            {
                if ($this->testFailingThumbnailStoreCount > 0) {
                    $this->testFailingThumbnailStoreCount--;
                    throw new InternalErrorException('Object storage "test": put failed: Could not contact remote server');
                }
                $this->testRenderedContentHashes[(string)$designDirectoryBoard->contentHash] = true;
                return null;
            }

            public function findForBoards(DesignDirectoryBoards $designDirectoryBoards, string $themeHash): DesignStudioThumbnails
            {
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

    /**
     * A render requests service without database, message bus and renderer: it records status changes and dispatched
     * runs, and "renders" a board by remembering its content hash.
     */
    protected function createService(DesignStudioThumbnailRenderRequest $renderRequest): DesignStudioThumbnailRenderRequestsService
    {
        $renderedContentHashes = &$this->renderedContentHashes;
        return new class($renderRequest, $renderedContentHashes) extends DesignStudioThumbnailRenderRequestsService {
            /** @var array[] the dispatched runs: [delaySeconds, shard, shardCount, changedBoardFollowUpCount, fingerprint, unchangedSince] */
            public array $dispatchedRuns = [];

            /** @var callable|null runs before a board is read for its render (the board key is passed) */
            public $beforeBoardIsRead = null;

            public int $currentTimestamp = 1000000;

            /** @var array[] the columns of every write of the request (each also stamps its modified time) */
            public array $requestWrites = [];

            /** true: a finished render goes through the service's own finishRender() (stores the thumbnail) */
            public bool $usesTheServiceFinishRender = false;

            public function __construct(public DesignStudioThumbnailRenderRequest $testRenderRequest, protected array &$testRenderedContentHashes)
            {
            }

            public function find($entityId, bool $useEntityRegistrCache = true): ?DesignStudioThumbnailRenderRequest
            {
                return $this->testRenderRequest;
            }

            public function findLatestRequest(DesignCanvasDefinition $canvasDefinition, string $themeHash): ?DesignStudioThumbnailRenderRequest
            {
                return $this->testRenderRequest;
            }

            public function dispatchRun(
                DesignStudioThumbnailRenderRequest $renderRequest,
                int $delaySeconds,
                int $shard = 0,
                int $shardCount = 1,
                int $changedBoardFollowUpCount = 0,
                ?string $remainingBoardsFingerprint = null,
                ?int $remainingBoardsUnchangedSince = null
            ): void {
                $this->dispatchedRuns[] = [$delaySeconds, $shard, $shardCount, $changedBoardFollowUpCount, $remainingBoardsFingerprint, $remainingBoardsUnchangedSince];
            }

            /** Runs the oldest dispatched run (as the consumer would) and returns its arguments. */
            public function runNextDispatchedRun(): array
            {
                $dispatchedRun = array_shift($this->dispatchedRuns);
                [, $shard, $shardCount, $changedBoardFollowUpCount, $remainingBoardsFingerprint, $remainingBoardsUnchangedSince] = $dispatchedRun;
                $this->processRequest(1, $shard, $shardCount, $changedBoardFollowUpCount, $remainingBoardsFingerprint, $remainingBoardsUnchangedSince);
                return $dispatchedRun;
            }

            protected function getThemeStyle(?string $themeReference): ?string
            {
                return null;
            }

            protected function setStatus(DesignStudioThumbnailRenderRequest $renderRequest, string $status, ?string $lastError): void
            {
                $renderRequest->status = $status;
                $renderRequest->lastError = $lastError;
                $this->writeRequestColumns($renderRequest, 'status', 'lastError');
            }

            protected function storeLastError(DesignStudioThumbnailRenderRequest $renderRequest, string $lastError): void
            {
                $renderRequest->lastError = $lastError;
                $this->writeRequestColumns($renderRequest, 'lastError');
            }

            protected function writeRequestColumns(DesignStudioThumbnailRenderRequest $renderRequest, string ...$propertyNames): void
            {
                $this->requestWrites[] = $propertyNames;
                $renderRequest->changeHistory->modifiedTime = DateTime::fromTimestamp($this->currentTimestamp);
            }

            protected function logRun(DesignStudioThumbnailRenderRequest $renderRequest, int $shard, int $shardCount, int $renderedBoardCount, int $startedAt): void
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
                if ($this->beforeBoardIsRead !== null) {
                    ($this->beforeBoardIsRead)((string)$designDirectoryBoard->boardKey);
                }
                if ($this->readBoardContentForRender($canvasDefinition, $designDirectoryBoard) === null) {
                    return null;
                }
                return (new ReflectionClass(ArgusDesignBoardRenderedThumbnails::class))->newInstanceWithoutConstructor();
            }

            protected function finishRender(
                DesignStudioThumbnailRenderRequest $renderRequest,
                DesignDirectoryBoard $designDirectoryBoard,
                ArgusDesignBoardRenderedThumbnails $renderer,
                int $shard,
                int $shardCount
            ): bool {
                if ($this->usesTheServiceFinishRender) {
                    return parent::finishRender($renderRequest, $designDirectoryBoard, $renderer, $shard, $shardCount);
                }
                $this->testRenderedContentHashes[(string)$designDirectoryBoard->contentHash] = true;
                return true;
            }
        };
    }

    protected function createRenderRequest(): DesignStudioThumbnailRenderRequest
    {
        $renderRequest = new DesignStudioThumbnailRenderRequest();
        $renderRequest->id = 1;
        $renderRequest->projectKey = 'app';
        $renderRequest->canvasKey = 'test';
        $renderRequest->themeHash = DesignThemeProviderService::computeThemeHash(null);
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_QUEUED;
        $renderRequest->lastError = null;
        $renderRequest->priorityBoardKeys = null;
        $renderRequest->attempts = 0;
        $renderRequest->changeHistory = new ChangeHistory();
        $renderRequest->changeHistory->createdTime = DateTime::fromTimestamp(1000000);
        return $renderRequest;
    }

    /** @var string[] the board keys listed in canvas.json */
    protected array $listedBoardKeys = [];

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
        // a regenerated file has a new size (the tests' versions differ in length)
        clearstatcache();
    }

    /**
     * @return string[] one board key of shard 0 and one of shard 1 (of 2)
     */
    protected function findBoardKeysOfTwoShards(): array
    {
        $boardKeysByShard = [];
        foreach (range(1, 50) as $number) {
            $boardKey = 'board-' . $number . '.html';
            $boardKeysByShard[DesignStudioThumbnailRenderRequestsService::getShardOfBoard($boardKey, 2)] ??= $boardKey;
        }
        return [$boardKeysByShard[0], $boardKeysByShard[1]];
    }

    public function testABoardChangedDuringTheRunIsRenderedInItsNewVersionAndTheLastShardClosesTheRequest(): void
    {
        [$boardOfShardZero, $boardOfShardOne] = $this->findBoardKeysOfTwoShards();
        $this->writeBoard($boardOfShardZero, '<p>zero v1</p>');
        $this->writeBoard($boardOfShardOne, '<p>one v1</p>');
        $renderRequest = $this->createRenderRequest();
        $designStudioThumbnailRenderRequestsService = $this->createService($renderRequest);
        // both boards are regenerated while the shards run: each shard finds its board changed since the listing
        $designStudioThumbnailRenderRequestsService->beforeBoardIsRead = function (string $boardKey) use ($boardOfShardZero, $boardOfShardOne): void {
            static $regeneratedBoardKeys = [];
            if (!isset($regeneratedBoardKeys[$boardKey])) {
                $regeneratedBoardKeys[$boardKey] = true;
                $this->writeBoard($boardKey, $boardKey === $boardOfShardZero ? '<p>zero v2, longer</p>' : '<p>one v2, longer</p>');
            }
        };

        $designStudioThumbnailRenderRequestsService->processRequest(1, 0, 2);
        $designStudioThumbnailRenderRequestsService->processRequest(1, 1, 2);

        // before: both shards ended silently and the request stayed RUNNING; now each one reads its board again
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);
        $this->assertCount(2, $designStudioThumbnailRenderRequestsService->dispatchedRuns);
        $this->assertSame(
            [DesignStudioThumbnailRenderRequestsService::CHANGED_BOARD_FOLLOW_UP_DELAY_SECONDS, 0, 2, 1, null, null],
            $designStudioThumbnailRenderRequestsService->dispatchedRuns[0]
        );
        $this->assertSame([], $this->renderedContentHashes);

        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        // shard 0 rendered its board in the new version and watches shard 1, whose run is still queued
        $this->assertSame([DesignDirectoryReaderService::computeContentHash('<p>zero v2, longer</p>') => true], $this->renderedContentHashes);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);

        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        $this->assertCount(2, $this->renderedContentHashes);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertNull($renderRequest->lastError);

        // the watching run of shard 0 sees the request closed and ends
        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        $this->assertSame([], $designStudioThumbnailRenderRequestsService->dispatchedRuns);
    }

    public function testAShardThatIsThroughClosesTheRequestWhenTheOtherShardsBoardsStayMissing(): void
    {
        [$boardOfShardZero, $boardOfShardOne] = $this->findBoardKeysOfTwoShards();
        $this->writeBoard($boardOfShardZero, '<p>zero</p>');
        $this->writeBoard($boardOfShardOne, '<p>one</p>');
        $renderRequest = $this->createRenderRequest();
        $designStudioThumbnailRenderRequestsService = $this->createService($renderRequest);

        // shard 1's message is lost: only shard 0 runs
        $designStudioThumbnailRenderRequestsService->processRequest(1, 0, 2);
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);
        $watchingRun = $designStudioThumbnailRenderRequestsService->dispatchedRuns[0];
        $this->assertSame(DesignStudioThumbnailRenderRequestsService::REMAINING_BOARDS_WATCH_DELAY_SECONDS, $watchingRun[0]);
        $this->assertNotNull($watchingRun[4]);

        // the missing board stays the same: the request stays open until the settle time is over, then closes
        $designStudioThumbnailRenderRequestsService->currentTimestamp += 60;
        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);
        $designStudioThumbnailRenderRequestsService->currentTimestamp += DesignStudioThumbnailRenderRequestsService::REMAINING_BOARDS_SETTLED_AFTER_SECONDS;
        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame('1 board(s) could not be rendered', $renderRequest->lastError);
        $this->assertSame([], $designStudioThumbnailRenderRequestsService->dispatchedRuns);
    }

    public function testAWatchingShardKeepsTheRequestOpenWhileTheOtherShardMakesProgress(): void
    {
        [$boardOfShardZero, $boardOfShardOne] = $this->findBoardKeysOfTwoShards();
        $this->writeBoard($boardOfShardZero, '<p>zero</p>');
        $this->writeBoard($boardOfShardOne, '<p>one</p>');
        $renderRequest = $this->createRenderRequest();
        $designStudioThumbnailRenderRequestsService = $this->createService($renderRequest);

        $designStudioThumbnailRenderRequestsService->processRequest(1, 0, 2);
        // shard 1's board changes meanwhile (a new version is missing): progress, the settle time starts again
        $designStudioThumbnailRenderRequestsService->currentTimestamp += DesignStudioThumbnailRenderRequestsService::REMAINING_BOARDS_SETTLED_AFTER_SECONDS;
        $this->writeBoard($boardOfShardOne, '<p>one, changed</p>');
        $designStudioThumbnailRenderRequestsService->runNextDispatchedRun();
        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_RUNNING, $renderRequest->status);
        $this->assertSame($designStudioThumbnailRenderRequestsService->currentTimestamp, $designStudioThumbnailRenderRequestsService->dispatchedRuns[0][5]);
    }

    public function testABoardThatChangesDuringEveryRunEndsTheFollowUpsWithAnError(): void
    {
        [$boardOfShardZero] = $this->findBoardKeysOfTwoShards();
        $this->writeBoard($boardOfShardZero, '<p>v0</p>');
        $renderRequest = $this->createRenderRequest();
        $designStudioThumbnailRenderRequestsService = $this->createService($renderRequest);
        $designStudioThumbnailRenderRequestsService->beforeBoardIsRead = function (string $boardKey): void {
            static $version = 0;
            $this->writeBoard($boardKey, '<p>version ' . str_repeat('x', ++$version) . '</p>');
        };

        $designStudioThumbnailRenderRequestsService->processRequest(1, 0, 1, DesignStudioThumbnailRenderRequestsService::MAX_CHANGED_BOARD_FOLLOW_UPS);

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame($boardOfShardZero . ': changed during every run, not rendered', $renderRequest->lastError);
        $this->assertSame([], $designStudioThumbnailRenderRequestsService->dispatchedRuns);
    }

    public function testAFailingObjectStoreIsRecordedRetriedAndTheRequestEndsDone(): void
    {
        $this->writeBoard('home.html', '<p>home</p>');
        $renderRequest = $this->createRenderRequest();
        $service = $this->createService($renderRequest);
        $service->usesTheServiceFinishRender = true;
        $this->failingThumbnailStoreCount = 1;

        $service->processRequest(1);

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER, $renderRequest->status);
        $this->assertStringContainsString('home.html: storing the thumbnail failed: Object storage', (string)$renderRequest->lastError);
        $this->assertSame(1, $renderRequest->attempts);
        $this->assertCount(1, $service->dispatchedRuns);
        $this->assertLessThanOrEqual(DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS, $service->dispatchedRuns[0][0]);

        $service->currentTimestamp += 60;
        $service->runNextDispatchedRun();

        $this->assertSame(DesignStudioThumbnailRenderRequest::STATUS_DONE, $renderRequest->status);
        $this->assertSame(0, $renderRequest->attempts);
    }

    public function testEveryRunWritesTheRequestAndStallingCountsFromTheLastWrite(): void
    {
        $this->writeBoard('home.html', '<p>home</p>');
        $renderRequest = $this->createRenderRequest();
        $service = $this->createService($renderRequest);
        $service->currentTimestamp = 1000000 + 3600;

        $this->assertTrue($service->isStalled($renderRequest), 'never written for an hour');
        $service->processRequest(1);

        $this->assertSame([], $service->requestWrites[0], 'the run itself writes the request first');
        $this->assertSame(1000000 + 3600, $renderRequest->changeHistory->modifiedTime->getTimestamp());
        $service->currentTimestamp += DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS - 1;
        $this->assertFalse($service->isStalled($renderRequest));
        $service->currentTimestamp += 2;
        $this->assertTrue($service->isStalled($renderRequest));
        $this->assertSame(300, DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS);
        $this->assertLessThan(
            DesignStudioThumbnailRenderRequestsService::STALLED_AFTER_SECONDS - 10,
            DesignStudioThumbnailRenderRequestsService::MAX_BACKOFF_SECONDS,
            'a waiting request retries (with jitter) before it counts as lost'
        );
    }
}
