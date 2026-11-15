<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardExportRegion;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * How a board is exported as SVG: the look, a part of the board and the device frame of a responsive board.
 */
class DesignStudioBoardSvgExportPostRequestDto extends RequestDto
{
    /** @var string|null Theme reference `<themeId>[:<mode>]`; omitted = the reference theme of the canvas */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 255)]
    public ?string $theme = null;

    /** @var DesignBoardExportRegion|null A part of the board in board pixels; omitted = the whole board */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?DesignBoardExportRegion $region = null;

    /** @var string|null Device frame of a responsive board (device key); omitted = its first frame */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 64)]
    public ?string $device = null;
}
