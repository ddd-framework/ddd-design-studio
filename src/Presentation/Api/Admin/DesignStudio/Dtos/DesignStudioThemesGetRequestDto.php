<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Narrows the list of themes.
 */
class DesignStudioThemesGetRequestDto extends RequestDto
{
    /** @var string|null Text the theme name contains */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Length(max: 100)]
    public ?string $search = null;
}
