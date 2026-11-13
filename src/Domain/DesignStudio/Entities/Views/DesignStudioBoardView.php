<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One board of a canvas as the studio page shows it: the file of the checkout with its review state and delivery
 * URLs. Never stored.
 */
class DesignStudioBoardView extends ValueObject
{
    /** @var string|null File name of the board in the canvas folder */
    public ?string $boardKey = null;

    /** @var string|null Short code of the board (`APP ONB-W2`): names it in chats and opens it on the canvas, null = none */
    public ?string $code = null;

    /** @var string|null Board title */
    public ?string $title = null;

    /** @var int Left edge, relative to the canvas selection */
    public int $x = 0;

    /** @var int Top edge, relative to the canvas selection */
    public int $y = 0;

    /** @var int Width in pixels */
    public int $width = 0;

    /** @var int Height in pixels */
    public int $height = 0;

    /** @var string|null Key of the section the board belongs to */
    public ?string $sectionKey = null;

    /** @var int Position within the canvas, starting at 1 */
    public int $displayOrder = 0;

    /** @var string|null Content hash of the board file (git blob hash) */
    public ?string $contentHash = null;

    /** @var string|null Review status (one of the configured statuses; the default without a stored one) */
    public ?string $status = null;

    /** @var bool The board file changed after the status was set */
    public bool $hasChangedSinceStatus = false;

    /** @var int Comment threads on the board that are not resolved */
    public int $openThreadCount = 0;

    /** @var string|null URL of the board for the sandboxed iframe (carries the content token) */
    public ?string $htmlUrl = null;

    /** @var string|null URL of the thumbnail in the requested look, null when there is none (yet) */
    public ?string $thumbnailUrl = null;

    /** @var string|null URL of the reduced thumbnail for far zoom levels, null when there is none (yet) */
    public ?string $thumbnailSmallUrl = null;

    /** @var string|null URL of the preview copy for the canvas (640 px wide, WebP / JPEG), null when there is no thumbnail */
    public ?string $thumbnailPreviewUrl = null;

    /** @var bool The layout adapts to the viewport width: the board is shown in device frames */
    public bool $isResponsive = false;

    /**
     * @var DesignStudioBoardFrameViews|null The device frames of a responsive board, side by side on the canvas (width
     *      and height of the board are then the extent of the frames in viewport mode); null for a fixed board
     */
    public ?DesignStudioBoardFrameViews $frames = null;

    /** @var string|null Path of the board's page in the site view (`/`, `/pricing`), null = no page */
    public ?string $route = null;
}
