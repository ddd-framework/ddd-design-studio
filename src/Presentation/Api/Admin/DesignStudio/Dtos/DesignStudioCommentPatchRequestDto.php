<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Changes of a comment; omitted fields stay as they are.
 */
class DesignStudioCommentPatchRequestDto extends RequestDto
{
    /** @var string|null New text (only the author may change it) */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(min: 1, max: DesignStudioComment::MAX_TEXT_LENGTH)]
    public ?string $text = null;

    /** @var bool|null Sets or clears the decision flag */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isDecision = null;
}
