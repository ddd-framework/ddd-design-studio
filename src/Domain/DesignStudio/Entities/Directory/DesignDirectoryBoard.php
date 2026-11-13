<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Directory;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One board as found in the canvas folder of the running checkout. The directory is the only source of boards; this
 * object is never stored.
 */
class DesignDirectoryBoard extends ValueObject
{
    /** @var string|null File name of the board in the canvas folder = its key in canvas.json */
    public ?string $boardKey = null;

    /** @var string|null Short code of the board (`APP ONB-W2`, DesignBoardCodesService), null when it has none */
    public ?string $code = null;

    /** @var string|null Title from canvas.json */
    public ?string $title = null;

    /** @var int Left edge on the canvas, relative to the canvas selection */
    public int $x = 0;

    /** @var int Top edge on the canvas, relative to the canvas selection */
    public int $y = 0;

    /** @var int Board width in px */
    public int $width = 0;

    /** @var int Board height in px */
    public int $height = 0;

    /** @var string|null Section the board belongs to (nearest section title above it) */
    public ?string $sectionKey = null;

    /** @var int Position within the canvas, top to bottom and left to right, starting at 1 */
    public int $displayOrder = 0;

    /** @var string|null Version of the board: the git blob hash of the file content (equals `git hash-object <file>`) */
    public ?string $contentHash = null;

    /** @var int File size in bytes */
    public int $byteSize = 0;

    /**
     * @var string|null Version of the asset files the board references (`assets/…`): changes when one of them is
     *      added, removed or replaced; null when the board references none
     */
    public ?string $assetsHash = null;

    /**
     * @var bool The board's layout adapts to the viewport width (`"responsive": true` of the board or the canvas in
     *      canvas.json, or `<meta name="design-responsive">` in the file): it is shown in device frames
     */
    public bool $isResponsive = false;

    /** @var string[] Keys of the devices a responsive board shows as frames on the canvas; empty for a fixed board */
    public array $frameDeviceKeys = [];

    /**
     * @var string|null Path of the page in the site view (`"route"` in canvas.json or
     *      `<meta name="design-route" content="/path">`), e.g. `/` or `/pricing`; null = the board is no page
     */
    public ?string $route = null;

    /**
     * @var string|null Set on a render target only (DesignStudioThumbnailsService::expandRenderTargets()): the device
     *      frame of a responsive board this target renders; width and height are then the device's viewport
     */
    public ?string $frameDeviceKey = null;

    /**
     * @return int|null the viewport width a render target is rendered at as a whole page; null for a fixed board
     */
    public function getFrameWidth(): ?int
    {
        return $this->frameDeviceKey !== null ? $this->width : null;
    }

    /**
     * @return string the board key, with the device frame of a render target
     */
    public function getRenderTargetKey(): string
    {
        return $this->frameDeviceKey !== null ? $this->boardKey . '@' . $this->frameDeviceKey : (string)$this->boardKey;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getRenderTargetKey());
    }
}
