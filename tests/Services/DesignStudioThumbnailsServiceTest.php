<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnails;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\DesignStudioThumbnailRenderRequest;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use PHPUnit\Framework\TestCase;

/**
 * Thumbnail keys (board version + look + renderer version) and the order missing boards are rendered in.
 */
class DesignStudioThumbnailsServiceTest extends TestCase
{
    /**
     * @param string[] $existingThumbnailKeys
     */
    protected function createService(array $existingThumbnailKeys = [], string $rendererVersion = 'r1'): DesignStudioThumbnailsService
    {
        return new class($existingThumbnailKeys, $rendererVersion) extends DesignStudioThumbnailsService {
            public function __construct(protected array $testThumbnailKeys, protected string $testRendererVersion)
            {
            }

            public function getRendererVersion(): string
            {
                return $this->testRendererVersion;
            }

            public function findForBoards(DesignDirectoryBoards $designDirectoryBoards, string $themeHash): DesignStudioThumbnails
            {
                $designStudioThumbnails = new DesignStudioThumbnails();
                foreach ($this->testThumbnailKeys as $index => $thumbnailKey) {
                    $designStudioThumbnail = new DesignStudioThumbnail();
                    $designStudioThumbnail->id = $index + 1;
                    $designStudioThumbnail->thumbnailKey = $thumbnailKey;
                    $designStudioThumbnails->add($designStudioThumbnail);
                }
                return $designStudioThumbnails;
            }
        };
    }

    protected function createBoards(string ...$boardKeys): DesignDirectoryBoards
    {
        $designDirectoryBoards = new DesignDirectoryBoards();
        foreach ($boardKeys as $boardKey) {
            $designDirectoryBoard = new DesignDirectoryBoard();
            $designDirectoryBoard->boardKey = $boardKey;
            $designDirectoryBoard->contentHash = sha1($boardKey);
            $designDirectoryBoards->add($designDirectoryBoard);
        }
        return $designDirectoryBoards;
    }

    public function testTheKeyChangesWithBoardThemeAndRenderer(): void
    {
        $noTheme = DesignThemeProviderService::computeThemeHash(null);
        $theme = DesignThemeProviderService::computeThemeHash('<style data-design-theme>:root{}</style>');
        $key = $this->createService()->computeThumbnailKey(sha1('a'), $noTheme);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame($key, $this->createService()->computeThumbnailKey(sha1('a'), $noTheme));
        $this->assertNotSame($key, $this->createService()->computeThumbnailKey(sha1('b'), $noTheme));
        $this->assertNotSame($key, $this->createService()->computeThumbnailKey(sha1('a'), $theme));
        $this->assertNotSame($key, $this->createService([], 'r2')->computeThumbnailKey(sha1('a'), $noTheme));
    }

    public function testListsMissingBoardsWithPriorityBoardsFirst(): void
    {
        $themeHash = DesignThemeProviderService::computeThemeHash(null);
        $boards = $this->createBoards('a.html', 'b.html', 'c.html', 'd.html');
        $existingKey = $this->createService()->computeThumbnailKey(sha1('b.html'), $themeHash);
        $missingBoards = $this->createService([$existingKey])->findBoardsWithoutThumbnail($boards, $themeHash, ['d.html', 'b.html', 'c.html']);
        $this->assertSame(
            ['d.html', 'c.html', 'a.html'],
            array_map(static fn(DesignDirectoryBoard $board): ?string => $board->boardKey, $missingBoards->getElements())
        );
    }

    public function testABoardAlwaysBelongsToTheSameShard(): void
    {
        $shards = [];
        foreach (['a.html', 'b.html', 'c.html', 'd.html', 'e.html', 'f.html'] as $boardKey) {
            $shard = DesignStudioThumbnailRenderRequestsService::getShardOfBoard($boardKey, 2);
            $this->assertSame($shard, DesignStudioThumbnailRenderRequestsService::getShardOfBoard($boardKey, 2));
            $this->assertContains($shard, [0, 1]);
            $shards[$shard] = true;
        }
        $this->assertCount(2, $shards);
        $this->assertSame(0, DesignStudioThumbnailRenderRequestsService::getShardOfBoard('a.html', 1));
    }

    public function testPriorityBoardKeysOfARequest(): void
    {
        $renderRequest = new DesignStudioThumbnailRenderRequest();
        $renderRequest->priorityBoardKeys = null;
        $this->assertSame([], $renderRequest->getPriorityBoardKeyList());
        $renderRequest->priorityBoardKeys = "a.html\nb.html";
        $this->assertSame(['a.html', 'b.html'], $renderRequest->getPriorityBoardKeyList());
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_WAITING_FOR_RENDERER;
        $this->assertTrue($renderRequest->isOpen());
        $renderRequest->status = DesignStudioThumbnailRenderRequest::STATUS_DONE;
        $this->assertFalse($renderRequest->isOpen());
    }
}
