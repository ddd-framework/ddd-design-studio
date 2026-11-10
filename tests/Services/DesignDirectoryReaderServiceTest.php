<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * DesignDirectoryReaderService reads a canvas from a folder: board subset by pattern, sections = nearest title above,
 * titles without boards dropped, offset to 0 / 0, content hash = git blob hash, hashes cached by mtime + size, missing
 * and oversized files as warnings, nothing outside the canvas folder readable.
 */
class DesignDirectoryReaderServiceTest extends TestCase
{
    protected string $projectRootDirectory;

    protected function setUp(): void
    {
        $this->projectRootDirectory = sys_get_temp_dir() . '/design-studio-reader-test-' . bin2hex(random_bytes(4));
        mkdir($this->projectRootDirectory . '/design/canvas', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRootDirectory);
    }

    protected function createService(?string $projectRootDirectory = null, int $boardMaximumBytes = 1000): DesignDirectoryReaderService
    {
        $projectRootDirectory ??= $this->projectRootDirectory;
        return new class($projectRootDirectory, $boardMaximumBytes) extends DesignDirectoryReaderService {
            /** @var array folder path => stored hashes */
            public array $storedFileHashes = [];

            /** @var int number of times the hashes were written */
            public int $storeCount = 0;

            public function __construct(protected string $testProjectRootDirectory, protected int $testBoardMaximumBytes)
            {
            }

            protected function getProjectRootDirectory(): string
            {
                return $this->testProjectRootDirectory;
            }

            protected function loadFileHashes(string $folderPath): array
            {
                return $this->storedFileHashes[$folderPath] ?? [];
            }

            protected function storeFileHashes(string $folderPath, array $fileHashes): void
            {
                $this->storedFileHashes[$folderPath] = $fileHashes;
                $this->storeCount++;
            }

            protected function getBoardMaximumBytes(): int
            {
                return $this->testBoardMaximumBytes;
            }

            protected function getCanvasJsonMaximumBytes(): int
            {
                return 100000;
            }
        };
    }

    protected function createCanvasDefinition(array $boardPatterns = ['a-*'], array $sectionPatterns = ['t*']): DesignCanvasDefinition
    {
        $canvasDefinition = new DesignCanvasDefinition();
        $canvasDefinition->projectKey = 'app';
        $canvasDefinition->canvasKey = 'test';
        $canvasDefinition->folder = 'design/canvas';
        $canvasDefinition->boardPatterns = $boardPatterns;
        $canvasDefinition->sectionPatterns = $sectionPatterns;
        return $canvasDefinition;
    }

    protected function writeCanvas(): void
    {
        $folder = $this->projectRootDirectory . '/design/canvas';
        file_put_contents($folder . '/canvas.json', json_encode([
            'title' => 'From JSON',
            'boards' => [
                'a-2.dc.html' => ['x' => 500, 'y' => 1100, 'w' => 390, 'h' => 844, 'title' => 'Second'],
                'a-1.dc.html' => ['x' => 100, 'y' => 1100, 'title' => 'First'],
                'a-3.dc.html' => ['x' => 100, 'y' => 3100, 'title' => 'Third'],
                'a-missing.dc.html' => ['x' => 0, 'y' => 0],
                'a-large.dc.html' => ['x' => 0, 'y' => 0],
                'b-other.dc.html' => ['x' => 0, 'y' => 0],
                '../escape.html' => ['x' => 0, 'y' => 0],
            ],
            'notes' => [
                't1' => ['x' => 100, 'y' => 1000, 'text' => ' One ', 'kind' => 'title1', 'maxW' => 900],
                't2' => ['x' => 100, 'y' => 2000, 'text' => 'Empty', 'kind' => 'title1'],
                't3' => ['x' => 100, 'y' => 3000, 'text' => 'Three', 'kind' => 'title1'],
                'n1' => ['x' => 0, 'y' => 0, 'text' => 'annotation', 'kind' => 'text'],
            ],
        ]));
        file_put_contents($folder . '/a-1.dc.html', "<html>one</html>\n");
        file_put_contents($folder . '/a-2.dc.html', '<html>two</html>');
        file_put_contents($folder . '/a-3.dc.html', '<html>three</html>');
        file_put_contents($folder . '/a-large.dc.html', str_repeat('x', 2000));
        file_put_contents($folder . '/b-other.dc.html', '<html>other</html>');
        file_put_contents($this->projectRootDirectory . '/design/escape.html', 'secret');
    }

    public function testReadsBoardsAndSectionsOfTheSubset(): void
    {
        $this->writeCanvas();
        $designDirectoryCanvas = $this->createService()->readCanvas($this->createCanvasDefinition());

        $this->assertSame('From JSON', $designDirectoryCanvas->title);
        $boards = $designDirectoryCanvas->boards->getElements();
        $this->assertSame(['a-1.dc.html', 'a-2.dc.html', 'a-3.dc.html'], array_map(fn($board) => $board->boardKey, $boards));
        $this->assertSame([1, 2, 3], array_map(fn($board) => $board->displayOrder, $boards));
        $this->assertSame(['t1', 't1', 't3'], array_map(fn($board) => $board->sectionKey, $boards));
        // offset: smallest x is 100, smallest y is the first title at 1000
        $this->assertSame([0, 100], [$boards[0]->x, $boards[0]->y]);
        $this->assertSame([400, 100], [$boards[1]->x, $boards[1]->y]);
        $this->assertSame([390, 844], [$boards[0]->width, $boards[0]->height]);

        $sections = $designDirectoryCanvas->sections->getElements();
        $this->assertSame(['t1', 't3'], array_map(fn($section) => $section->sectionKey, $sections));
        $this->assertSame('One', $sections[0]->title);
        $this->assertSame([0, 0, 900], [$sections[0]->x, $sections[0]->y, $sections[0]->maximumWidth]);

        $this->assertCount(2, $designDirectoryCanvas->warnings);
        $this->assertStringContainsString('a-missing.dc.html', $designDirectoryCanvas->warnings[0]);
        $this->assertStringContainsString('a-large.dc.html', $designDirectoryCanvas->warnings[1]);
    }

    public function testContentHashIsTheGitBlobHash(): void
    {
        $this->writeCanvas();
        $designDirectoryCanvas = $this->createService()->readCanvas($this->createCanvasDefinition());
        $boardPath = $this->projectRootDirectory . '/design/canvas/a-1.dc.html';
        $gitHash = trim((string)shell_exec('git hash-object ' . escapeshellarg($boardPath)));
        $this->assertSame(40, strlen($gitHash));
        $this->assertSame($gitHash, $designDirectoryCanvas->boards->first()->contentHash);
        $this->assertSame(filesize($boardPath), $designDirectoryCanvas->boards->first()->byteSize);
    }

    public function testHashesAreCachedByModificationTimeAndSize(): void
    {
        $this->writeCanvas();
        $service = $this->createService();
        $canvasDefinition = $this->createCanvasDefinition();
        $firstHash = $service->readCanvas($canvasDefinition)->boards->first()->contentHash;
        $this->assertSame(1, $service->storeCount);

        // unchanged folder: nothing re-hashed, nothing stored
        $service->readCanvas($canvasDefinition);
        $this->assertSame(1, $service->storeCount);

        // a stale cache entry with matching mtime + size is trusted (proves the file was not read again)
        $folderPath = realpath($this->projectRootDirectory . '/design/canvas');
        $service->storedFileHashes[$folderPath]['a-1.dc.html'][2] = 'cached';
        $this->assertSame('cached', $service->readCanvas($canvasDefinition)->boards->first()->contentHash);

        // changed content: new hash
        file_put_contents($folderPath . '/a-1.dc.html', '<html>one, changed</html>');
        clearstatcache();
        $changedHash = $service->readCanvas($canvasDefinition)->boards->first()->contentHash;
        $this->assertNotSame('cached', $changedHash);
        $this->assertNotSame($firstHash, $changedHash);
    }

    public function testWithoutSectionPatternsTitleNotesAreSections(): void
    {
        $this->writeCanvas();
        $designDirectoryCanvas = $this->createService()->readCanvas($this->createCanvasDefinition(['a-*'], []));
        $this->assertSame(['t1', 't3'], array_map(fn($section) => $section->sectionKey, $designDirectoryCanvas->sections->getElements()));
    }

    public function testBoardContentOnlyFromTheCanvas(): void
    {
        $this->writeCanvas();
        $service = $this->createService();
        $canvasDefinition = $this->createCanvasDefinition();
        $this->assertSame('<html>two</html>', $service->readBoardContent($canvasDefinition, 'a-2.dc.html'));
        $this->assertNull($service->readBoardContent($canvasDefinition, 'b-other.dc.html'), 'outside the board patterns');
        $this->assertNull($service->readBoardContent($canvasDefinition, '../escape.html'), 'outside the folder');
        $this->assertNull($service->readBoardContent($canvasDefinition, 'canvas.json'), 'not a board file');
        $this->assertNull($service->readBoardContent($canvasDefinition, 'a-large.dc.html'), 'over the size limit');
        $this->assertNull($service->readBoardContent($canvasDefinition, 'a-missing.dc.html'));
    }

    public function testMissingFolderIsAWarning(): void
    {
        $canvasDefinition = $this->createCanvasDefinition();
        $canvasDefinition->folder = 'design/not-here';
        $designDirectoryCanvas = $this->createService()->readCanvas($canvasDefinition);
        $this->assertTrue($designDirectoryCanvas->boards->isEmpty());
        $this->assertCount(1, $designDirectoryCanvas->warnings);
    }
}
