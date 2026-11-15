<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Exports;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\DesignStudio\Repo\Argus\Exports\ArgusDesignBoardVectorExport;

/**
 * One board version in one look as a vector graphic, made by the renderer service (job `vector`, loaded via
 * ArgusDesignBoardVectorExport): the board file (asset files inlined), the theme's `<style>` element and an optional
 * region go in; the SVG, the raster picture of the same region, a comparison picture and the share of differing pixels
 * come out. Never stored.
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusDesignBoardVectorExport::class)]
class DesignBoardVectorExport extends ValueObject
{
    /** @var int[] Renderer answers that mean "try again later" (see DesignBoardRenderedThumbnails::RETRYABLE_STATUS_CODES) */
    public const array RETRYABLE_STATUS_CODES = [401, 404, 429, 502, 503, 504];

    /** @var string The board file with its asset files inlined */
    public string $html = '';

    /** @var int Board width in pixels (a device frame: the device's viewport width) */
    public int $width = 390;

    /** @var int Board height in pixels (a device frame: the device's viewport height) */
    public int $height = 844;

    /** @var bool The whole page at `width` (a device frame of a responsive board) */
    public bool $fullPage = false;

    /** @var bool The board's scripts run before the export (its canvas runs board scripts) */
    public bool $runsScripts = false;

    /** @var string SHA-256 of the theme's `<style>` element (of the empty string without a theme) */
    public string $themeHash = '';

    /** @var string The theme's `<style data-design-theme>` element, empty = no theme */
    public string $themeStyle = '';

    /** @var DesignBoardExportRegion|null The part of the board to export; null = the whole board */
    public ?DesignBoardExportRegion $region = null;

    /** @var string|null The SVG document (filled by the load) */
    public ?string $svg = null;

    /** @var string|null PNG bytes of the raster picture of the same region (filled by the load) */
    public ?string $png = null;

    /** @var string|null PNG bytes: raster | SVG as an `<img>` shows it | differing pixels in red (filled by the load) */
    public ?string $comparisonPng = null;

    /** @var float|null Share of the pixels whose colour differs between the raster picture and the SVG (0–1) */
    public ?float $differingPixelRatio = null;

    /** @var int|null Width of the exported region in pixels */
    public ?int $exportWidth = null;

    /** @var int|null Height of the exported region in pixels */
    public ?int $exportHeight = null;

    /** @var int|null HTTP status of a failed export (null: transport failure or success) */
    public ?int $failureStatusCode = null;

    /** @var string|null Why the export failed (null = success) */
    public ?string $failureReason = null;

    /**
     * @return bool the export failed in a way that heals by itself (renderer busy, restarting, unreachable, older
     *              renderer without the job)
     */
    public function isRetryableFailure(): bool
    {
        return $this->failureReason !== null
            && ($this->failureStatusCode === null || in_array($this->failureStatusCode, static::RETRYABLE_STATUS_CODES, true));
    }
}
