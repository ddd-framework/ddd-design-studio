<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A new comment thread on a board.
 */
class DesignStudioCommentCreateRequestDto extends RequestDto
{
    /** @var string The comment text */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(min: 1, max: DesignStudioComment::MAX_TEXT_LENGTH)]
    public string $text;

    /** @var float|null Pin position from the left edge in board pixels; omitted = a comment on the whole board */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?float $x = null;

    /** @var float|null Pin position from the top edge in board pixels */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?float $y = null;

    /** @var bool|null Marks the comment as a decision */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isDecision = null;
}
