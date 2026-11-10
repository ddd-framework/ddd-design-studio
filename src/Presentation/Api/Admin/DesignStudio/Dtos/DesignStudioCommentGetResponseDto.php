<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One comment.
 */
class DesignStudioCommentGetResponseDto extends RestResponseDto
{
    /** @var DesignStudioComment The comment */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioComment $designStudioComment;
}
