<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Resolves a thread, optionally with a last reply.
 */
class DesignStudioCommentResolveRequestDto extends RequestDto
{
    /** @var string|null Reply written before the thread is resolved */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: DesignStudioComment::MAX_TEXT_LENGTH)]
    public ?string $replyText = null;
}
