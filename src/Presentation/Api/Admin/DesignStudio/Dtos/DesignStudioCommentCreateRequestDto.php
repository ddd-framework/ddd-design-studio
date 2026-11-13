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

    /**
     * @var int|null Viewport width in CSS pixels the pin was placed in (a responsive board: x / y are positions in the
     *      page at this width); omitted = board pixels
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $viewportWidth = null;

    /** @var int|null Viewport height in CSS pixels the pin was placed in */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $viewportHeight = null;

    /** @var string|null Device preset the pin was placed in (a device key of the canvas view); omitted = a free width */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 64)]
    public ?string $deviceKey = null;

    /** @var bool|null Marks the comment as a decision */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isDecision = null;
}
