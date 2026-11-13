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
 * - A board's code (`APP ONB-W2`) comes from the canvas code and its file name or its `code` (DesignBoardCodesService).
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

    /** @var string `<meta name="design-responsive">` in a board file marks the board as responsive */
    public const string RESPONSIVE_META_NAME = 'design-responsive';

    /** @var string `<meta name="design-route" content="/path">` in a board file names its page in the site view */
    public const string ROUTE_META_NAME = 'design-route';

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

    /** @var string Folder of a canvas that holds the files boards reference (images), next to canvas.json */
    public const string ASSETS_FOLDER_NAME = 'assets';

    /** @var int Fallback of the config `DesignStudio.content.assetMaximumBytes` */
    public const int DEFAULT_ASSET_MAXIMUM_BYTES = 5 * 1024 * 1024;

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

        $designDirectoryBoards = $this->selectBoards($canvasDescription, $folderPath, $canvasDefinition, $designDirectoryCanvas);
        if (!$designDirectoryBoards->count()) {
            return $designDirectoryCanvas;
        }
        /** @var DesignBoardCodesService $designBoardCodesService */
        $designBoardCodesService = DDDService::instance()->getService(DesignBoardCodesService::class);
        array_push(
            $designDirectoryCanvas->warnings,
            ...$designBoardCodesService->assignCodes($designDirectoryBoards, $canvasDescription['boards'], $canvasDefinition)
        );
        $designDirectorySections = $this->selectSections($canvasDescription, $canvasDefinition);
        $this->assignBoardsToSections($designDirectoryBoards, $designDirectorySections);
        $usedSectionKeys = [];
        $horizontalPositions = [];
        $verticalPositions = [];
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            if ($designDirectoryBoard->sectionKey !== null) {
                $usedSectionKeys[$designDirectoryBoard->sectionKey] = true;
            }
            $horizontalPositions[] = $designDirectoryBoard->x;
            $verticalPositions[] = $designDirectoryBoard->y;
        }
        foreach ($designDirectorySections->getElements() as $designDirectorySection) {
            if (isset($usedSectionKeys[$designDirectorySection->sectionKey])) {
                $horizontalPositions[] = $designDirectorySection->x;
                $verticalPositions[] = $designDirectorySection->y;
            }
        }
        $offsetX = min($horizontalPositions);
        $offsetY = min($verticalPositions);

        $displayOrder = 0;
        foreach ($designDirectorySections->getElements() as $designDirectorySection) {
            if (!isset($usedSectionKeys[$designDirectorySection->sectionKey])) {
                continue;
            }
            $designDirectorySection->x -= $offsetX;
            $designDirectorySection->y -= $offsetY;
            $designDirectorySection->displayOrder = ++$displayOrder;
            $designDirectoryCanvas->sections->add($designDirectorySection);
        }
        $displayOrder = 0;
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            $designDirectoryBoard->x -= $offsetX;
            $designDirectoryBoard->y -= $offsetY;
            $designDirectoryBoard->displayOrder = ++$displayOrder;
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
     * The version of a board file in this checkout, from the hashes the last canvas read stored when the file is
     * unchanged since (else computed from the file).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey
     * @return string|null null when the board is not in this checkout
     */
    public function getCheckoutContentHash(DesignCanvasDefinition $canvasDefinition, string $boardKey): ?string
    {
        $folderPath = $this->getCanvasFolderPath($canvasDefinition);
        if ($folderPath === null || !$this->isBoardKeyOfCanvas($boardKey, $canvasDefinition)) {
            return null;
        }
        $boardPath = $folderPath . '/' . $boardKey;
        if (!is_file($boardPath)) {
            return null;
        }
        $cachedFileHash = $this->loadFileHashes($folderPath)[$boardKey] ?? null;
        if (is_array($cachedFileHash) && $cachedFileHash[0] === (int)filemtime($boardPath) && $cachedFileHash[1] === (int)filesize($boardPath)) {
            return $cachedFileHash[2];
        }
        $boardContent = $this->readBoardContent($canvasDefinition, $boardKey);
        return $boardContent === null ? null : static::computeContentHash($boardContent);
    }

    /**
     * The file of an asset path in the canvas' `assets/` folder of this checkout.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $assetPath path below `assets/` (DesignBoardAssetsService::isValidAssetPath())
     * @return string|null absolute path; null when the path is not a valid asset path, the file is missing, larger
     *                     than `DesignStudio.content.assetMaximumBytes`, or resolves outside the `assets/` folder
     *                     (a symbolic link pointing elsewhere)
     */
    public function getAssetFilePath(DesignCanvasDefinition $canvasDefinition, string $assetPath): ?string
    {
        if (!DesignBoardAssetsService::isValidAssetPath($assetPath)) {
            return null;
        }
        $folderPath = $this->getCanvasFolderPath($canvasDefinition);
        $assetsFolderPath = $folderPath === null ? false : realpath($folderPath . '/' . static::ASSETS_FOLDER_NAME);
        if ($assetsFolderPath === false || !is_dir($assetsFolderPath)) {
            return null;
        }
        $assetFilePath = realpath($assetsFolderPath . '/' . $assetPath);
        if (
            $assetFilePath === false
            || !str_starts_with($assetFilePath, $assetsFolderPath . '/')
            || !is_file($assetFilePath)
            || filesize($assetFilePath) > $this->getAssetMaximumBytes()
        ) {
            return null;
        }
        return $assetFilePath;
    }

    /**
     * The version of an asset file (its git blob hash), cached per path, modification time and size.
     * @param string $assetFilePath a path returned by getAssetFilePath()
     * @return string|null null when the file cannot be read
     */
    public function getAssetContentHash(string $assetFilePath): ?string
    {
        $modificationTime = @filemtime($assetFilePath);
        $byteSize = @filesize($assetFilePath);
        if ($modificationTime === false || $byteSize === false) {
            return null;
        }
        $fileStateKey = sha1($assetFilePath . ':' . $modificationTime . ':' . $byteSize);
        $cachedContentHash = $this->loadAssetContentHash($fileStateKey);
        if ($cachedContentHash !== null) {
            return $cachedContentHash;
        }
        $content = @file_get_contents($assetFilePath);
        if (!is_string($content)) {
            return null;
        }
        $contentHash = static::computeContentHash($content);
        $this->storeAssetContentHash($fileStateKey, $contentHash);
        return $contentHash;
    }

    /**
     * @param string $fileStateKey hash of path, modification time and size of an asset file
     * @return string|null the content hash stored for that state of the file
     */
    protected function loadAssetContentHash(string $fileStateKey): ?string
    {
        $contentHash = Cache::instance()->get('designStudio.assetHash.' . $fileStateKey);
        return is_string($contentHash) ? $contentHash : null;
    }

    /**
     * @param string $fileStateKey
     * @param string $contentHash
     * @return void
     */
    protected function storeAssetContentHash(string $fileStateKey, string $contentHash): void
    {
        Cache::instance()->set('designStudio.assetHash.' . $fileStateKey, $contentHash, static::FILE_HASHES_CACHE_TTL);
    }

    /**
     * @return int largest asset file delivered, in bytes
     */
    public function getAssetMaximumBytes(): int
    {
        return (int)(Config::get('DesignStudio.content.assetMaximumBytes') ?? static::DEFAULT_ASSET_MAXIMUM_BYTES);
    }

    /**
     * @param array $canvasDescription the decoded canvas.json
     * @param string $folderPath
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryCanvas $designDirectoryCanvas receives a warning per board that cannot be read
     * @return DesignDirectoryBoards the readable boards with the positions of canvas.json, top to bottom, left to
     *                               right; section and display order are not set yet
     */
    protected function selectBoards(
        array $canvasDescription,
        string $folderPath,
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryCanvas $designDirectoryCanvas
    ): DesignDirectoryBoards {
        $boardMaximumBytes = $this->getBoardMaximumBytes();
        /** @var DesignBoardAssetsService $designBoardAssetsService */
        $designBoardAssetsService = DDDService::instance()->getService(DesignBoardAssetsService::class);
        /** @var DesignDevicesService $designDevicesService */
        $designDevicesService = DDDService::instance()->getService(DesignDevicesService::class);
        $cachedFileHashes = $this->loadFileHashes($folderPath);
        $fileHashes = [];
        $boards = [];
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
            if (
                is_array($cachedFileHash) && $cachedFileHash[0] === $modificationTime && $cachedFileHash[1] === $byteSize
                && is_array($cachedFileHash[3] ?? null) && is_bool($cachedFileHash[4] ?? null)
            ) {
                $contentHash = $cachedFileHash[2];
                $assetPaths = $cachedFileHash[3];
                $isMarkedResponsiveInFile = $cachedFileHash[4];
                $routeInFile = $cachedFileHash[5] ?? null;
            } else {
                $boardContent = (string)file_get_contents($boardPath);
                $contentHash = static::computeContentHash($boardContent);
                $assetPaths = DesignBoardAssetsService::findReferencedAssetPaths($boardContent);
                $isMarkedResponsiveInFile = static::findMetaContent($boardContent, static::RESPONSIVE_META_NAME) !== null;
                $routeInFile = static::normalizeRoute(static::findMetaContent($boardContent, static::ROUTE_META_NAME));
            }
            $fileHashes[$boardKey] = [$modificationTime, $byteSize, $contentHash, $assetPaths, $isMarkedResponsiveInFile, $routeInFile];
            $designDirectoryBoard = new DesignDirectoryBoard();
            $designDirectoryBoard->boardKey = $boardKey;
            $designDirectoryBoard->title = (string)($boardDescription['title'] ?? $boardKey);
            $designDirectoryBoard->x = (int)($boardDescription['x'] ?? 0);
            $designDirectoryBoard->y = (int)($boardDescription['y'] ?? 0);
            $designDirectoryBoard->width = (int)($boardDescription['w'] ?? static::DEFAULT_BOARD_WIDTH);
            $designDirectoryBoard->height = (int)($boardDescription['h'] ?? static::DEFAULT_BOARD_HEIGHT);
            $designDirectoryBoard->contentHash = $contentHash;
            $designDirectoryBoard->byteSize = $byteSize;
            $designDirectoryBoard->isResponsive = (bool)($boardDescription['responsive'] ?? $canvasDescription['responsive'] ?? false)
                || $isMarkedResponsiveInFile;
            if ($designDirectoryBoard->isResponsive) {
                $designDirectoryBoard->frameDeviceKeys = $designDevicesService->resolveFrameDeviceKeys(
                    is_array($boardDescription['devices'] ?? null) ? $boardDescription['devices'] : null,
                    is_array($canvasDescription['devices'] ?? null) ? $canvasDescription['devices'] : null
                );
            }
            $designDirectoryBoard->route = static::normalizeRoute(is_string($boardDescription['route'] ?? null) ? $boardDescription['route'] : null)
                ?? $routeInFile;
            if ($assetPaths) {
                $designDirectoryBoard->assetsHash = $designBoardAssetsService
                    ->findCurrentAssetReferences($canvasDefinition, $assetPaths)
                    ->computeAssetsHash();
            }
            $boards[] = $designDirectoryBoard;
        }
        if ($fileHashes !== $cachedFileHashes) {
            $this->storeFileHashes($folderPath, $fileHashes);
        }
        usort($boards, static fn(DesignDirectoryBoard $first, DesignDirectoryBoard $second) => [$first->y, $first->x] <=> [$second->y, $second->x]);
        // a route names one page: the first board (top to bottom) keeps it, a later one loses it with a warning
        $boardKeysByRoute = [];
        foreach ($boards as $designDirectoryBoard) {
            if ($designDirectoryBoard->route === null) {
                continue;
            }
            if (isset($boardKeysByRoute[$designDirectoryBoard->route])) {
                $designDirectoryCanvas->warnings[] = "Route {$designDirectoryBoard->route} of board {$designDirectoryBoard->boardKey} is taken by "
                    . $boardKeysByRoute[$designDirectoryBoard->route];
                $designDirectoryBoard->route = null;
                continue;
            }
            $boardKeysByRoute[$designDirectoryBoard->route] = $designDirectoryBoard->boardKey;
        }
        $designDirectoryBoards = new DesignDirectoryBoards();
        foreach ($boards as $designDirectoryBoard) {
            $designDirectoryBoards->add($designDirectoryBoard);
        }
        return $designDirectoryBoards;
    }

    /**
     * @param string $boardContent
     * @param string $metaName
     * @return string|null the content of the board's `<meta name="…">` (an empty string for a meta without content),
     *                     null when the board has no such meta
     */
    public static function findMetaContent(string $boardContent, string $metaName): ?string
    {
        $quotedMetaName = preg_quote($metaName, '/');
        if (!preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?' . $quotedMetaName . '["\']?(?=[\s\/>])[^>]*>/i', $boardContent, $metaMatch)) {
            return null;
        }
        return preg_match('/\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $metaMatch[0], $contentMatch)
            ? html_entity_decode($contentMatch[1] !== '' ? $contentMatch[1] : (($contentMatch[2] ?? '') !== '' ? $contentMatch[2] : ($contentMatch[3] ?? '')))
            : '';
    }

    /**
     * @param string|null $route
     * @return string|null the route as a path with a leading slash and without a trailing one (`/` stays), null when
     *                     it is no path (empty, external, with a query)
     */
    public static function normalizeRoute(?string $route): ?string
    {
        $route = trim((string)$route);
        if ($route === '' || !preg_match('#^/?[A-Za-z0-9._~\-/%]*$#', $route) || str_contains($route, '//') || str_contains($route, '..')) {
            return null;
        }
        $route = '/' . trim($route, '/');
        return $route;
    }

    /**
     * @param array $canvasDescription the decoded canvas.json
     * @param DesignCanvasDefinition $canvasDefinition
     * @return DesignDirectorySections the section titles with the positions of canvas.json, top to bottom; display
     *                                 order is not set yet
     */
    protected function selectSections(array $canvasDescription, DesignCanvasDefinition $canvasDefinition): DesignDirectorySections
    {
        $sections = [];
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
            $designDirectorySection = new DesignDirectorySection();
            $designDirectorySection->sectionKey = $noteKey;
            $designDirectorySection->title = trim((string)($noteDescription['text'] ?? $noteKey));
            $designDirectorySection->x = (int)($noteDescription['x'] ?? 0);
            $designDirectorySection->y = (int)($noteDescription['y'] ?? 0);
            $designDirectorySection->maximumWidth = (int)($noteDescription['maxW'] ?? 0);
            $sections[] = $designDirectorySection;
        }
        usort($sections, static fn(DesignDirectorySection $first, DesignDirectorySection $second) => $first->y <=> $second->y);
        $designDirectorySections = new DesignDirectorySections();
        foreach ($sections as $designDirectorySection) {
            $designDirectorySections->add($designDirectorySection);
        }
        return $designDirectorySections;
    }

    /**
     * Sets each board's section: the nearest section title above it.
     * @param DesignDirectoryBoards $designDirectoryBoards
     * @param DesignDirectorySections $designDirectorySections top to bottom
     * @return void
     */
    protected function assignBoardsToSections(DesignDirectoryBoards $designDirectoryBoards, DesignDirectorySections $designDirectorySections): void
    {
        foreach ($designDirectoryBoards->getElements() as $designDirectoryBoard) {
            foreach ($designDirectorySections->getElements() as $designDirectorySection) {
                if ($designDirectorySection->y <= $designDirectoryBoard->y) {
                    $designDirectoryBoard->sectionKey = $designDirectorySection->sectionKey;
                }
            }
        }
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
     * @return array board key => [modification time, size, content hash, referenced asset paths] as stored by the last
     *               read of the folder
     */
    protected function loadFileHashes(string $folderPath): array
    {
        $fileHashes = Cache::instance()->get($this->getFileHashesCacheKey($folderPath));
        return is_array($fileHashes) ? $fileHashes : [];
    }

    /**
     * @param string $folderPath
     * @param array $fileHashes board key => [modification time, size, content hash, referenced asset paths]
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
