<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryCanvas;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectorySection;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectorySections;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use JsonException;

/**
 * Reads a canvas from its folder in the running checkout. The directory says what exists: `canvas.json` (boards: file
 * name → x, y, w, h, title; notes: key → x, y, text, maxW, kind) and one HTML file per board. Nothing read here is
 * written to the database, so several checkouts on one database never overwrite or delete each other's boards.
 *
 * - A board's version is the git blob hash of its file content, computed from the file (no `.git` needed).
 * - Notes are section titles; a board belongs to the nearest section title above it; titles without a board are left
 *   out; positions are shifted so the selection starts at 0 / 0.
 * - Hashes are cached per file by modification time and size, so an unchanged canvas is read without opening its
 *   board files again.
 */
class DesignDirectoryReaderService extends Service
{
    /** @var string File name of the canvas description inside a canvas folder */
    public const string CANVAS_FILE_NAME = 'canvas.json';

    /** @var string Board files end with this suffix */
    public const string BOARD_FILE_SUFFIX = '.html';

    /** @var string Note kinds of canvas.json that are section titles when a canvas declares no section patterns */
    public const string SECTION_NOTE_KIND_PREFIX = 'title';

    /** @var int Default board width when canvas.json names none */
    public const int DEFAULT_BOARD_WIDTH = 390;

    /** @var int Default board height when canvas.json names none */
    public const int DEFAULT_BOARD_HEIGHT = 844;

    /** @var int Fallback of the config `DesignStudio.content.boardMaximumBytes` */
    public const int DEFAULT_BOARD_MAXIMUM_BYTES = 16 * 1024 * 1024;

    /** @var int Fallback of the config `DesignStudio.content.canvasJsonMaximumBytes` */
    public const int DEFAULT_CANVAS_JSON_MAXIMUM_BYTES = 4 * 1024 * 1024;

    /** @var int Lifetime of the per-folder hash cache; an entry is re-validated against mtime + size on every read */
    public const int FILE_HASHES_CACHE_TTL = 30 * 86400;

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @return DesignDirectoryCanvas sections and boards of the canvas as they are in the checkout right now
     * @throws BadRequestException when canvas.json is not valid JSON or has no boards object
     */
    public function readCanvas(DesignCanvasDefinition $canvasDefinition): DesignDirectoryCanvas
    {
        $designDirectoryCanvas = new DesignDirectoryCanvas();
        $designDirectoryCanvas->projectKey = $canvasDefinition->projectKey;
        $designDirectoryCanvas->canvasKey = $canvasDefinition->canvasKey;
        $designDirectoryCanvas->title = $canvasDefinition->label;
        $designDirectoryCanvas->sections = new DesignDirectorySections();
        $designDirectoryCanvas->boards = new DesignDirectoryBoards();

        $folderPath = $this->getCanvasFolderPath($canvasDefinition);
        $canvasJsonPath = $folderPath === null ? null : $folderPath . '/' . static::CANVAS_FILE_NAME;
        if ($canvasJsonPath === null || !is_file($canvasJsonPath)) {
            $designDirectoryCanvas->warnings[] = "No {$canvasDefinition->folder}/" . static::CANVAS_FILE_NAME . ' in this checkout';
            return $designDirectoryCanvas;
        }
        if (filesize($canvasJsonPath) > $this->getCanvasJsonMaximumBytes()) {
            throw new BadRequestException("{$canvasDefinition->folder}/" . static::CANVAS_FILE_NAME . ' is too large');
        }
        try {
            $canvasDescription = json_decode((string)file_get_contents($canvasJsonPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new BadRequestException(
                "{$canvasDefinition->folder}/" . static::CANVAS_FILE_NAME . ' is not valid JSON: ' . $jsonException->getMessage()
            );
        }
        if (!is_array($canvasDescription) || !is_array($canvasDescription['boards'] ?? null)) {
            throw new BadRequestException("{$canvasDefinition->folder}/" . static::CANVAS_FILE_NAME . ' has no "boards" object');
        }
        $designDirectoryCanvas->title ??= isset($canvasDescription['title']) ? (string)$canvasDescription['title'] : $canvasDefinition->canvasKey;

        $boardRows = $this->selectBoardRows($canvasDescription, $folderPath, $canvasDefinition, $designDirectoryCanvas);
        $sectionRows = $this->selectSectionRows($canvasDescription, $canvasDefinition);
        $sectionKeysByBoardKey = $this->assignBoardsToSections($boardRows, $sectionRows);
        $sectionRows = array_intersect_key($sectionRows, array_flip($sectionKeysByBoardKey));
        if ($boardRows === []) {
            return $designDirectoryCanvas;
        }
        $offsetX = min(array_merge(array_column($boardRows, 'x'), array_column($sectionRows, 'x')));
        $offsetY = min(array_merge(array_column($boardRows, 'y'), array_column($sectionRows, 'y')));

        $displayOrder = 0;
        foreach ($sectionRows as $sectionKey => $sectionRow) {
            $designDirectorySection = new DesignDirectorySection();
            $designDirectorySection->sectionKey = (string)$sectionKey;
            $designDirectorySection->title = $sectionRow['title'];
            $designDirectorySection->x = $sectionRow['x'] - $offsetX;
            $designDirectorySection->y = $sectionRow['y'] - $offsetY;
            $designDirectorySection->maximumWidth = $sectionRow['maximumWidth'];
            $designDirectorySection->displayOrder = ++$displayOrder;
            $designDirectoryCanvas->sections->add($designDirectorySection);
        }
        $displayOrder = 0;
        foreach ($boardRows as $boardKey => $boardRow) {
            $designDirectoryBoard = new DesignDirectoryBoard();
            $designDirectoryBoard->boardKey = (string)$boardKey;
            $designDirectoryBoard->title = $boardRow['title'];
            $designDirectoryBoard->x = $boardRow['x'] - $offsetX;
            $designDirectoryBoard->y = $boardRow['y'] - $offsetY;
            $designDirectoryBoard->width = $boardRow['width'];
            $designDirectoryBoard->height = $boardRow['height'];
            $designDirectoryBoard->sectionKey = $sectionKeysByBoardKey[$boardKey] ?? null;
            $designDirectoryBoard->displayOrder = ++$displayOrder;
            $designDirectoryBoard->contentHash = $boardRow['contentHash'];
            $designDirectoryBoard->byteSize = $boardRow['byteSize'];
            $designDirectoryCanvas->boards->add($designDirectoryBoard);
        }
        return $designDirectoryCanvas;
    }

    /**
     * The current content of one board file.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey file name of the board inside the canvas folder
     * @return string|null null when the board is not in this checkout, is outside the canvas or exceeds the size limit
     */
    public function readBoardContent(DesignCanvasDefinition $canvasDefinition, string $boardKey): ?string
    {
        $folderPath = $this->getCanvasFolderPath($canvasDefinition);
        if ($folderPath === null || !$this->isBoardKeyOfCanvas($boardKey, $canvasDefinition)) {
            return null;
        }
        $boardPath = $folderPath . '/' . $boardKey;
        if (!is_file($boardPath) || filesize($boardPath) > $this->getBoardMaximumBytes()) {
            return null;
        }
        $content = file_get_contents($boardPath);
        return is_string($content) ? $content : null;
    }

    /**
     * The version of a board: git's blob hash of the content, the value `git hash-object <file>` prints. A developer
     * or an agent can compare a local file with the studio without asking it.
     * @param string $content
     * @return string 40 hex characters
     */
    public static function computeContentHash(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /**
     * @param string $boardKey
     * @param DesignCanvasDefinition $canvasDefinition
     * @return bool true when the key is a plain board file name that the canvas' board patterns accept
     */
    public function isBoardKeyOfCanvas(string $boardKey, DesignCanvasDefinition $canvasDefinition): bool
    {
        return $boardKey !== ''
            && basename($boardKey) === $boardKey
            && !str_starts_with($boardKey, '.')
            && str_ends_with($boardKey, static::BOARD_FILE_SUFFIX)
            && ($canvasDefinition->boardPatterns === [] || $this->matchesOneOf($boardKey, $canvasDefinition->boardPatterns));
    }

    /**
     * @param array $canvasDescription the decoded canvas.json
     * @param string $folderPath
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryCanvas $designDirectoryCanvas receives a warning per board that cannot be read
     * @return array board key => [x, y, width, height, title, contentHash, byteSize], top to bottom, left to right
     */
    protected function selectBoardRows(
        array $canvasDescription,
        string $folderPath,
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryCanvas $designDirectoryCanvas
    ): array {
        $boardMaximumBytes = $this->getBoardMaximumBytes();
        $cachedFileHashes = $this->loadFileHashes($folderPath);
        $fileHashes = [];
        $boardRows = [];
        foreach ($canvasDescription['boards'] as $boardKey => $boardDescription) {
            if (!is_string($boardKey) || !is_array($boardDescription) || !$this->isBoardKeyOfCanvas($boardKey, $canvasDefinition)) {
                continue;
            }
            $boardPath = $folderPath . '/' . $boardKey;
            if (!is_file($boardPath)) {
                $designDirectoryCanvas->warnings[] = "Board file $boardKey of " . static::CANVAS_FILE_NAME . ' is missing';
                continue;
            }
            $byteSize = (int)filesize($boardPath);
            if ($byteSize > $boardMaximumBytes) {
                $designDirectoryCanvas->warnings[] = "Board file $boardKey is too large ($byteSize bytes)";
                continue;
            }
            $modificationTime = (int)filemtime($boardPath);
            $cachedFileHash = $cachedFileHashes[$boardKey] ?? null;
            if (is_array($cachedFileHash) && $cachedFileHash[0] === $modificationTime && $cachedFileHash[1] === $byteSize) {
                $contentHash = $cachedFileHash[2];
            } else {
                $contentHash = static::computeContentHash((string)file_get_contents($boardPath));
            }
            $fileHashes[$boardKey] = [$modificationTime, $byteSize, $contentHash];
            $boardRows[$boardKey] = [
                'x' => (int)($boardDescription['x'] ?? 0),
                'y' => (int)($boardDescription['y'] ?? 0),
                'width' => (int)($boardDescription['w'] ?? static::DEFAULT_BOARD_WIDTH),
                'height' => (int)($boardDescription['h'] ?? static::DEFAULT_BOARD_HEIGHT),
                'title' => (string)($boardDescription['title'] ?? $boardKey),
                'contentHash' => $contentHash,
                'byteSize' => $byteSize,
            ];
        }
        if ($fileHashes !== $cachedFileHashes) {
            $this->storeFileHashes($folderPath, $fileHashes);
        }
        uasort($boardRows, static fn(array $first, array $second) => [$first['y'], $first['x']] <=> [$second['y'], $second['x']]);
        return $boardRows;
    }

    /**
     * @param array $canvasDescription the decoded canvas.json
     * @param DesignCanvasDefinition $canvasDefinition
     * @return array note key => [x, y, title, maximumWidth], top to bottom
     */
    protected function selectSectionRows(array $canvasDescription, DesignCanvasDefinition $canvasDefinition): array
    {
        $sectionRows = [];
        foreach (($canvasDescription['notes'] ?? []) as $noteKey => $noteDescription) {
            if (!is_string($noteKey) || !is_array($noteDescription)) {
                continue;
            }
            $isSection = $canvasDefinition->sectionPatterns === []
                ? str_starts_with((string)($noteDescription['kind'] ?? ''), static::SECTION_NOTE_KIND_PREFIX)
                : $this->matchesOneOf($noteKey, $canvasDefinition->sectionPatterns);
            if (!$isSection) {
                continue;
            }
            $sectionRows[$noteKey] = [
                'x' => (int)($noteDescription['x'] ?? 0),
                'y' => (int)($noteDescription['y'] ?? 0),
                'title' => trim((string)($noteDescription['text'] ?? $noteKey)),
                'maximumWidth' => (int)($noteDescription['maxW'] ?? 0),
            ];
        }
        uasort($sectionRows, static fn(array $first, array $second) => $first['y'] <=> $second['y']);
        return $sectionRows;
    }

    /**
     * @param array $boardRows
     * @param array $sectionRows top to bottom
     * @return string[] board key => note key of the nearest section title above the board
     */
    protected function assignBoardsToSections(array $boardRows, array $sectionRows): array
    {
        $sectionKeysByBoardKey = [];
        foreach ($boardRows as $boardKey => $boardRow) {
            foreach ($sectionRows as $sectionKey => $sectionRow) {
                if ($sectionRow['y'] <= $boardRow['y']) {
                    $sectionKeysByBoardKey[$boardKey] = (string)$sectionKey;
                }
            }
        }
        return $sectionKeysByBoardKey;
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @return string|null absolute path of the canvas folder; null when it does not exist in this checkout or resolves
     *                     to a place outside the project root (a symbolic link pointing elsewhere)
     */
    protected function getCanvasFolderPath(DesignCanvasDefinition $canvasDefinition): ?string
    {
        $projectRootDirectory = realpath($this->getProjectRootDirectory());
        if ($projectRootDirectory === false || ($canvasDefinition->folder ?? '') === '') {
            return null;
        }
        $folderPath = realpath($projectRootDirectory . '/' . $canvasDefinition->folder);
        if ($folderPath === false || !is_dir($folderPath) || !str_starts_with($folderPath . '/', $projectRootDirectory . '/')) {
            return null;
        }
        return $folderPath;
    }

    /**
     * @return string the project root canvas folders are relative to
     */
    protected function getProjectRootDirectory(): string
    {
        return DDDService::instance()->getRootDir();
    }

    /**
     * @param string $folderPath
     * @return array board key => [modification time, size, content hash] as stored by the last read of the folder
     */
    protected function loadFileHashes(string $folderPath): array
    {
        $fileHashes = Cache::instance()->get($this->getFileHashesCacheKey($folderPath));
        return is_array($fileHashes) ? $fileHashes : [];
    }

    /**
     * @param string $folderPath
     * @param array $fileHashes board key => [modification time, size, content hash]
     * @return void
     */
    protected function storeFileHashes(string $folderPath, array $fileHashes): void
    {
        Cache::instance()->set($this->getFileHashesCacheKey($folderPath), $fileHashes, static::FILE_HASHES_CACHE_TTL);
    }

    /**
     * @param string $folderPath
     * @return string
     */
    protected function getFileHashesCacheKey(string $folderPath): string
    {
        return 'designStudio.fileHashes.' . sha1($folderPath);
    }

    /**
     * @return int
     */
    protected function getBoardMaximumBytes(): int
    {
        return (int)(Config::get('DesignStudio.content.boardMaximumBytes') ?? static::DEFAULT_BOARD_MAXIMUM_BYTES);
    }

    /**
     * @return int
     */
    protected function getCanvasJsonMaximumBytes(): int
    {
        return (int)(Config::get('DesignStudio.content.canvasJsonMaximumBytes') ?? static::DEFAULT_CANVAS_JSON_MAXIMUM_BYTES);
    }

    /**
     * @param string $value
     * @param string[] $patterns fnmatch patterns
     * @return bool
     */
    protected function matchesOneOf(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $value)) {
                return true;
            }
        }
        return false;
    }
}
