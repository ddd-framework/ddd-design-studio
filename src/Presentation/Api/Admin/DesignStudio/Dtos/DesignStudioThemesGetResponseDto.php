<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Themes\DesignThemes;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Themes the boards can be shown in.
 */
class DesignStudioThemesGetResponseDto extends RestResponseDto
{
    /** @var DesignThemes The themes the application offers */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignThemes $designThemes;
}
