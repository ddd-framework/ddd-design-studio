<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Names a comment.
 */
class DesignStudioCommentGetRequestDto extends RequestDto
{
    /** @var int The id of the comment */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $designStudioCommentId;
}
