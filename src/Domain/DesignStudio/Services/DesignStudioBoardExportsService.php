<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoards;
use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardExportRegion;
use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardVectorExport;
use DDD\Domain\DesignStudio\Entities\Views\DesignStudioBoardSvgExportView;
use DDD\Domain\DesignStudio\Repo\Argus\Exports\ArgusDesignBoardVectorExport;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Imagick;
use ImagickException;

/**
 * Boards as files for use outside the studio: one board version of this checkout in one look as SVG, made by the
 * renderer service (job `vector`). Needs the renderer (config `DesignStudio.renderer`, renderer 1.2.0); nothing is
 * stored.
 */
class DesignStudioBoardExportsService extends Service
{
    /** @var int Quality of the WebP the export compares its SVG with (as a site would serve the raster picture) */
    public const int COMPARISON_WEBP_QUALITY = 80;

    /**
     * The board of a canvas named by its file name or its code (`APP ONB-W2`, on its canvas also `ONB-W2` or `W2`).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKeyOrCode
     * @return DesignDirectoryBoard
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function findBoard(DesignCanvasDefinition $canvasDefinition, string $boardKeyOrCode): DesignDirectoryBoard
    {
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $codeMatchingBoards = [];
        foreach ($designDirectoryReaderService->readCanvas($canvasDefinition)->boards->getElements() as $designDirectoryBoard) {
            if ($designDirectoryBoard->boardKey === $boardKeyOrCode) {
                return $designDirectoryBoard;
            }
            if ($designDirectoryBoard->code !== null && DesignBoardCodesService::codeMatches($boardKeyOrCode, $designDirectoryBoard->code)) {
                $codeMatchingBoards[] = $designDirectoryBoard;
            }
        }
        if (count($codeMatchingBoards) === 1) {
            return $codeMatchingBoards[0];
        }
        throw new NotFoundException("No board (or more than one) of canvas $canvasDefinition->canvasKey is named $boardKeyOrCode");
    }

    /**
     * Exports a board of this checkout as SVG. A responsive board is exported in one device frame (the whole page at the
     * device's width).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param string|null $themeReference `<themeId>[:<mode>]`; null = the board as authored
     * @param DesignBoardExportRegion|null $region a part of the board; null = the whole board
     * @param string|null $frameDeviceKey the device frame of a responsive board; null = its first frame
     * @return DesignBoardVectorExport the SVG, raster picture and comparison, or the failure (failureReason)
     * @throws BadRequestException the renderer is not configured, the board cannot be read, an unknown device frame
     */
    public function exportBoardAsSvg(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $designDirectoryBoard,
        ?string $themeReference,
        ?DesignBoardExportRegion $region = null,
        ?string $frameDeviceKey = null
    ): DesignBoardVectorExport {
        /** @var DesignStudioThumbnailsService $designStudioThumbnailsService */
        $designStudioThumbnailsService = DDDService::instance()->getService(DesignStudioThumbnailsService::class);
        if (!$designStudioThumbnailsService->isRendererEnabled()) {
            throw new BadRequestException('No renderer is configured (config DesignStudio.renderer): a board can only be exported through the renderer service');
        }
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $boardContent = $designDirectoryReaderService->readBoardContent($canvasDefinition, (string)$designDirectoryBoard->boardKey);
        if ($boardContent === null) {
            throw new BadRequestException("Board $designDirectoryBoard->boardKey cannot be read in this checkout");
        }
        /** @var DesignBoardAssetsService $designBoardAssetsService */
        $designBoardAssetsService = DDDService::instance()->getService(DesignBoardAssetsService::class);
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        $themeStyle = $themeReference !== null ? $designThemeProviderService->getThemeStyleForReference($themeReference) : null;
        if ($themeReference !== null && $themeStyle === null) {
            throw new BadRequestException("Unknown theme $themeReference (app:design-studio:theme lists the themes)");
        }
        $renderTarget = $this->findRenderTarget($designDirectoryBoard, $frameDeviceKey);
        $argusDesignBoardVectorExport = $this->createVectorExport();
        // the renderer loads nothing from the network: the asset files travel inside the board
        $argusDesignBoardVectorExport->html = $designBoardAssetsService->inlineAssetReferences($boardContent, $canvasDefinition);
        $argusDesignBoardVectorExport->width = max(1, $renderTarget->width);
        $argusDesignBoardVectorExport->height = max(1, $renderTarget->height);
        $argusDesignBoardVectorExport->fullPage = $renderTarget->frameDeviceKey !== null;
        $argusDesignBoardVectorExport->runsScripts = $renderTarget->runsScripts;
        $argusDesignBoardVectorExport->themeStyle = $themeStyle ?? '';
        $argusDesignBoardVectorExport->themeHash = DesignThemeProviderService::computeThemeHash($themeStyle);
        $argusDesignBoardVectorExport->region = $region;
        $argusDesignBoardVectorExport->export();
        return $argusDesignBoardVectorExport;
    }

    /**
     * Exports a board as SVG for the studio page: the SVG with its sizes and its difference from the raster picture.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param string|null $themeReference
     * @param DesignBoardExportRegion|null $region
     * @param string|null $frameDeviceKey
     * @return DesignStudioBoardSvgExportView
     * @throws BadRequestException the renderer is not configured or could not export the board
     * @throws ImagickException
     */
    public function buildBoardSvgExportView(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $designDirectoryBoard,
        ?string $themeReference,
        ?DesignBoardExportRegion $region = null,
        ?string $frameDeviceKey = null
    ): DesignStudioBoardSvgExportView {
        $designBoardVectorExport = $this->exportBoardAsSvg($canvasDefinition, $designDirectoryBoard, $themeReference, $region, $frameDeviceKey);
        if ($designBoardVectorExport->failureReason !== null || $designBoardVectorExport->svg === null) {
            throw new BadRequestException('The renderer could not export the board: ' . ($designBoardVectorExport->failureReason ?? 'no SVG')
                . ($designBoardVectorExport->failureStatusCode === 404 ? ' (exporting needs renderer 1.2.0)' : ''));
        }
        $designStudioBoardSvgExportView = new DesignStudioBoardSvgExportView();
        $designStudioBoardSvgExportView->boardKey = $designDirectoryBoard->boardKey;
        $designStudioBoardSvgExportView->fileName = static::buildFileName((string)$designDirectoryBoard->boardKey, $themeReference, $frameDeviceKey, $region);
        $designStudioBoardSvgExportView->themeReference = $themeReference;
        $designStudioBoardSvgExportView->deviceKey = $designDirectoryBoard->isResponsive ? $frameDeviceKey : null;
        $designStudioBoardSvgExportView->region = $region;
        $designStudioBoardSvgExportView->svg = $designBoardVectorExport->svg;
        $designStudioBoardSvgExportView->width = (int)$designBoardVectorExport->exportWidth;
        $designStudioBoardSvgExportView->height = (int)$designBoardVectorExport->exportHeight;
        $designStudioBoardSvgExportView->svgByteSize = strlen($designBoardVectorExport->svg);
        $designStudioBoardSvgExportView->svgGzipByteSize = strlen((string)gzencode($designBoardVectorExport->svg, 9));
        $designStudioBoardSvgExportView->rasterPngByteSize = strlen((string)$designBoardVectorExport->png);
        $webp = $designBoardVectorExport->png !== null ? $this->convertPngToWebp($designBoardVectorExport->png) : null;
        $designStudioBoardSvgExportView->rasterWebpByteSize = $webp !== null ? strlen($webp) : null;
        $designStudioBoardSvgExportView->differingPixelRatio = (float)$designBoardVectorExport->differingPixelRatio;
        $designStudioBoardSvgExportView->comparisonImageDataUrl = $designBoardVectorExport->comparisonPng !== null
            ? 'data:image/png;base64,' . base64_encode($designBoardVectorExport->comparisonPng)
            : null;
        return $designStudioBoardSvgExportView;
    }

    /**
     * @param string $boardKey
     * @param string|null $themeReference
     * @param string|null $frameDeviceKey
     * @param DesignBoardExportRegion|null $region
     * @return string `<board>[-<theme>][-<device>][-<region>].svg`, only letters, digits, `-` and `_`
     */
    public static function buildFileName(string $boardKey, ?string $themeReference, ?string $frameDeviceKey, ?DesignBoardExportRegion $region): string
    {
        $parts = [(string)preg_replace('/(\.dc)?\.html$/', '', $boardKey)];
        foreach ([$themeReference, $frameDeviceKey, $region?->toRegionText()] as $part) {
            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }
        return trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', implode('-', $parts)), '-') . '.svg';
    }

    /**
     * @return ArgusDesignBoardVectorExport a new export through the renderer (a test double replaces it)
     */
    protected function createVectorExport(): ArgusDesignBoardVectorExport
    {
        return new ArgusDesignBoardVectorExport();
    }

    /**
     * The raster picture as WebP (quality COMPARISON_WEBP_QUALITY), to compare the SVG's size with; null when the image
     * library has no WebP.
     * @param string $png
     * @return string|null
     * @throws ImagickException
     */
    public function convertPngToWebp(string $png): ?string
    {
        if (Imagick::queryFormats('WEBP') === []) {
            return null;
        }
        $imagick = new Imagick();
        $imagick->readImageBlob($png);
        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality(static::COMPARISON_WEBP_QUALITY);
        $webp = $imagick->getImageBlob();
        $imagick->clear();
        return $webp;
    }

    /**
     * @param DesignDirectoryBoard $designDirectoryBoard
     * @param string|null $frameDeviceKey
     * @return DesignDirectoryBoard the board itself (a fixed board) or its device frame (a responsive board)
     * @throws BadRequestException
     */
    protected function findRenderTarget(DesignDirectoryBoard $designDirectoryBoard, ?string $frameDeviceKey): DesignDirectoryBoard
    {
        if (!$designDirectoryBoard->isResponsive) {
            if ($frameDeviceKey !== null) {
                throw new BadRequestException("Board $designDirectoryBoard->boardKey is not responsive: it has no device frames");
            }
            return $designDirectoryBoard;
        }
        /** @var DesignStudioThumbnailsService $designStudioThumbnailsService */
        $designStudioThumbnailsService = DDDService::instance()->getService(DesignStudioThumbnailsService::class);
        $boardsOfOneBoard = new DesignDirectoryBoards();
        $boardsOfOneBoard->add($designDirectoryBoard);
        foreach ($designStudioThumbnailsService->expandRenderTargets($boardsOfOneBoard)->getElements() as $renderTarget) {
            if ($frameDeviceKey === null || $renderTarget->frameDeviceKey === $frameDeviceKey) {
                return $renderTarget;
            }
        }
        throw new BadRequestException("Board $designDirectoryBoard->boardKey has no device frame $frameDeviceKey (frames: "
            . implode(', ', $designDirectoryBoard->frameDeviceKeys) . ')');
    }
}
