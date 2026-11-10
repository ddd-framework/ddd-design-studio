<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Directory;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One section of a canvas: a title note of canvas.json that has at least one board below it. Never stored.
 */
class DesignDirectorySection extends ValueObject
{
    /** @var string|null Key of the note in canvas.json */
    public ?string $sectionKey = null;

    /** @var string|null Section title */
    public ?string $title = null;

    /** @var int Left edge of the title, relative to the canvas selection */
    public int $x = 0;

    /** @var int Top edge of the title, relative to the canvas selection */
    public int $y = 0;

    /** @var int Width the title may take, 0 = unbounded */
    public int $maximumWidth = 0;

    /** @var int Position within the canvas, top to bottom, starting at 1 */
    public int $displayOrder = 0;
}
