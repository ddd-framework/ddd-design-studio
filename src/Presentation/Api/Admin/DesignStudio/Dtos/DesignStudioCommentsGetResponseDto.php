<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComments;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Comments of a canvas.
 */
class DesignStudioCommentsGetResponseDto extends RestResponseDto
{
    /** @var DesignStudioComments Thread roots and replies, oldest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioComments $designStudioComments;
}
