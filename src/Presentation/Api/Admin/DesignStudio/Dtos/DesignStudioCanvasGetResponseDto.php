<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Views\DesignStudioCanvasView;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * A canvas with its sections and boards.
 */
class DesignStudioCanvasGetResponseDto extends RestResponseDto
{
    /** @var DesignStudioCanvasView The canvas as the studio page shows it */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioCanvasView $designStudioCanvasView;
}
