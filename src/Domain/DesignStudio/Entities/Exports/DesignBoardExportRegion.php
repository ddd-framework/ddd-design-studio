<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Exports;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * A part of a board in its pixels (the region of a design reference): the renderer clamps it to the board.
 */
class DesignBoardExportRegion extends ValueObject
{
    /** @var int Left edge in board pixels */
    public int $x = 0;

    /** @var int Top edge in board pixels */
    public int $y = 0;

    /** @var int Width in pixels */
    public int $width = 1;

    /** @var int Height in pixels */
    public int $height = 1;

    /**
     * @param string $regionText `x,y,width,height` in board pixels, e.g. `0,560,390,200`
     * @return static
     * @throws BadRequestException
     */
    public static function fromRegionText(string $regionText): static
    {
        if (!preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*$/', $regionText, $matches)
            || (int)$matches[3] < 1 || (int)$matches[4] < 1
        ) {
            throw new BadRequestException("Region \"$regionText\": expected x,y,width,height in board pixels (width and height at least 1)");
        }
        $designBoardExportRegion = new static();
        $designBoardExportRegion->x = (int)$matches[1];
        $designBoardExportRegion->y = (int)$matches[2];
        $designBoardExportRegion->width = (int)$matches[3];
        $designBoardExportRegion->height = (int)$matches[4];
        return $designBoardExportRegion;
    }

    /**
     * @return string `x,y,width,height`
     */
    public function toRegionText(): string
    {
        return "$this->x,$this->y,$this->width,$this->height";
    }
}
