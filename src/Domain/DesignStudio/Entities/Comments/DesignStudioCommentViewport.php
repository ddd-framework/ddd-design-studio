<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Comments;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\DesignStudio\Services\DesignDevicesService;

/**
 * The viewport a comment on a responsive board is placed in: its pin positions are positions in the page at this
 * width. Never stored as such (the comment keeps width, height and device key).
 */
class DesignStudioCommentViewport extends ValueObject
{
    /** @var int Viewport width in CSS pixels */
    public int $width = 0;

    /** @var int Viewport height in CSS pixels */
    public int $height = 0;

    /** @var string|null Device preset, null = a free width */
    public ?string $deviceKey = null;

    /**
     * @param int|null $width
     * @param int|null $height
     * @param string|null $deviceKey
     * @return static|null the viewport, null without a usable width (a comment on a fixed board)
     */
    public static function fromWidthHeightAndDeviceKey(?int $width, ?int $height, ?string $deviceKey): ?static
    {
        if ($width === null || $width < DesignDevicesService::MINIMUM_DEVICE_WIDTH || $width > DesignDevicesService::MAXIMUM_DEVICE_WIDTH) {
            return null;
        }
        $designStudioCommentViewport = new static();
        $designStudioCommentViewport->width = $width;
        $designStudioCommentViewport->height = max(1, min(DesignDevicesService::MAXIMUM_DEVICE_HEIGHT, (int)($height ?? $width)));
        $deviceKey = trim((string)$deviceKey);
        $designStudioCommentViewport->deviceKey = $deviceKey !== '' ? mb_substr($deviceKey, 0, 64) : null;
        return $designStudioCommentViewport;
    }

    /**
     * @return string `<width>x<height>`, with the device key when there is one: `393x852 iphone-16`
     */
    public function toReferenceText(): string
    {
        return $this->width . 'x' . $this->height . ($this->deviceKey !== null ? ' ' . $this->deviceKey : '');
    }
}
