<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Asks for the missing thumbnails of a canvas in a look.
 */
class DesignStudioThumbnailRenderRequestPostRequestDto extends RequestDto
{
    /** @var string|null Theme reference `<themeId>[:<mode>]`; omitted = the reference theme of the canvas */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 255)]
    public ?string $theme = null;

    /** @var string[]|null Boards on screen, rendered first */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $priorityBoardKeys = null;
}
