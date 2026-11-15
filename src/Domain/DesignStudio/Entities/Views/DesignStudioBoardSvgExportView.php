<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardExportRegion;

/**
 * A board exported as SVG, as the studio page offers it for download: the SVG, its size next to the raster picture's
 * and how far it differs from it. Never stored.
 */
class DesignStudioBoardSvgExportView extends ValueObject
{
    /** @var string|null File name of the board */
    public ?string $boardKey = null;

    /** @var string|null Suggested file name of the SVG (`<board>-<theme>.svg`) */
    public ?string $fileName = null;

    /** @var string|null Reference of the theme the board was exported in, null = as authored */
    public ?string $themeReference = null;

    /** @var string|null Device frame of a responsive board, null for a fixed board */
    public ?string $deviceKey = null;

    /** @var DesignBoardExportRegion|null The exported part of the board, null = the whole board */
    public ?DesignBoardExportRegion $region = null;

    /** @var string|null The SVG document */
    public ?string $svg = null;

    /** @var int Width of the SVG in pixels */
    public int $width = 0;

    /** @var int Height of the SVG in pixels */
    public int $height = 0;

    /** @var int Size of the SVG in bytes */
    public int $svgByteSize = 0;

    /** @var int Size of the SVG compressed with gzip, as a web server sends it */
    public int $svgGzipByteSize = 0;

    /** @var int Size of the raster picture as PNG */
    public int $rasterPngByteSize = 0;

    /** @var int|null Size of the raster picture as WebP (quality 80), null when the server's image library has no WebP */
    public ?int $rasterWebpByteSize = null;

    /** @var float Share of the pixels whose colour differs between the raster picture and the SVG (0–1) */
    public float $differingPixelRatio = 0.0;

    /** @var string|null The comparison picture as a `data:image/png` URL: raster | SVG | differing pixels in red */
    public ?string $comparisonImageDataUrl = null;
}
