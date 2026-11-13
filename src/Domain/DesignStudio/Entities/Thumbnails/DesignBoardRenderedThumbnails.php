<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails;

use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One board rendered by the renderer service (loaded via ArgusDesignBoardRenderedThumbnails): the board file and the
 * theme's `<style>` element go in, the PNG comes out. Never stored.
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusDesignBoardRenderedThumbnails::class)]
class DesignBoardRenderedThumbnails extends ValueObject
{
    /**
     * @var int[] Renderer answers that mean "try again later" (retried with backoff): 404 = the route / service is
     * not deployed (yet), 401 = token not (yet) configured, 429 busy, 502–504 restarting / slow
     */
    public const array RETRYABLE_STATUS_CODES = [401, 404, 429, 502, 503, 504];

    /** @var string The board file (the renderer removes scripts itself) */
    public string $html = '';

    /** @var int Board width in pixels */
    public int $width = 390;

    /** @var int Board height in pixels */
    public int $height = 844;

    /** @var string SHA-256 of the theme's `<style>` element (of the empty string without a theme) */
    /**
     * @var bool Screenshot of the whole page at `width` (a device frame of a responsive board); the picture's height is
     *      then the page height, at least `height`. false = exactly `width` × `height`
     */
    public bool $fullPage = false;

    /**
     * @var bool The board's scripts run before the picture (its canvas runs board scripts); false = the renderer
     *      removes them
     */
    public bool $runsScripts = false;

    public string $themeHash = '';

    /** @var string The theme's `<style data-design-theme>` element, empty = no theme */
    public string $themeStyle = '';

    /** @var string|null PNG bytes (filled by the load) */
    public ?string $png = null;

    /** @var int|null HTTP status of a failed render (null: transport failure or success) */
    public ?int $failureStatusCode = null;

    /** @var string|null Why the render failed (null = success) */
    public ?string $failureReason = null;

    /**
     * @return bool the render failed in a way that heals by itself (renderer busy, restarting, unreachable)
     */
    public function isRetryableFailure(): bool
    {
        return $this->failureReason !== null
            && ($this->failureStatusCode === null || in_array($this->failureStatusCode, static::RETRYABLE_STATUS_CODES, true));
    }
}
