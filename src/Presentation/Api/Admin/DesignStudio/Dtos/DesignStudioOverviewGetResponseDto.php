<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Views\DesignStudioOverview;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The start page of the studio.
 */
class DesignStudioOverviewGetResponseDto extends RestResponseDto
{
    /** @var DesignStudioOverview Projects, canvases and the studio configuration */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioOverview $designStudioOverview;
}
