<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One device frame of a responsive board on the canvas: the same file at a device's viewport width. Never stored.
 */
class DesignStudioBoardFrameView extends ValueObject
{
    /** @var string|null Key of the device (DesignDevice::$deviceKey) */
    public ?string $deviceKey = null;

    /** @var int Viewport width in CSS pixels */
    public int $width = 0;

    /** @var int Viewport height in CSS pixels (the frame's height in viewport mode) */
    public int $height = 0;

    /**
     * @var int|null Height of the whole page at this width as the renderer measured it (the frame's height in full-page
     *      mode); null until the frame has a thumbnail
     */
    public ?int $pageHeight = null;

    /** @var string|null Thumbnail of the whole page at this width (PNG), null until rendered */
    public ?string $thumbnailUrl = null;

    /** @var string|null Reduced thumbnail, null until rendered */
    public ?string $thumbnailSmallUrl = null;

    /** @var string|null Preview copy for the canvas (640 px wide, WebP / JPEG), null until rendered */
    public ?string $thumbnailPreviewUrl = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->deviceKey);
    }
}
