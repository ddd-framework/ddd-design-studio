<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Names a canvas; `theme` chooses the look of its boards.
 */
class DesignStudioCanvasGetRequestDto extends RequestDto
{
    /** @var string Key of the design project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $projectKey;

    /** @var string Key of the canvas within the project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $canvasKey;

    /** @var string|null Theme reference `<themeId>[:<mode>]` (mode light or dark); omitted = the reference theme of the canvas */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 255)]
    public ?string $theme = null;
}
