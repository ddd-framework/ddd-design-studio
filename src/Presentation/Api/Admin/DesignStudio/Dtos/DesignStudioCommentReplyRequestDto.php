<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A reply in the thread of a comment.
 */
class DesignStudioCommentReplyRequestDto extends RequestDto
{
    /** @var string The reply text */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(min: 1, max: DesignStudioComment::MAX_TEXT_LENGTH)]
    public string $text;
}
