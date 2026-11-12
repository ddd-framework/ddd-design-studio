<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * An asset file (image) of a canvas' `assets/` folder for a board in the sandboxed iframe.
 */
class DesignStudioAssetContentGetRequestDto extends RequestDto
{
    /** @var string Key of the design project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $projectKey;

    /** @var string Key of the canvas within the project */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $canvasKey;

    /** @var string Path of the file below the canvas' `assets/` folder */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 255)]
    public string $assetPath;

    /** @var string|null Content token from the canvas response */
    #[Parameter(in: Parameter::QUERY, required: true)]
    #[Length(max: 2000)]
    public ?string $token = null;

    /** @var string|null Version of the file (git blob hash); omitted = the file of the checkout */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 40)]
    public ?string $v = null;
}
