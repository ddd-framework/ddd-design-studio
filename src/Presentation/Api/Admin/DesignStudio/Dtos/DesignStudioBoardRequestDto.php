<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Names a board of a canvas.
 */
class DesignStudioBoardRequestDto extends RequestDto
{
    /** @var string Key of the design project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $projectKey;

    /** @var string Key of the canvas within the project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $canvasKey;

    /** @var string File name of the board in the canvas folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 255)]
    public string $boardKey;
}
