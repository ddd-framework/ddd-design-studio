<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The fingerprint of a canvas.
 */
class DesignStudioCanvasRevisionGetResponseDto extends RestResponseDto
{
    /** @var string Changes with every board file, position, comment and status of the canvas */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $revision;
}
