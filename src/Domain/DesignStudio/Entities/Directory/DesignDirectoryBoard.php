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
}
