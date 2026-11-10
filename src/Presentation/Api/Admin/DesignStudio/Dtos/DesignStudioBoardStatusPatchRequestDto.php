<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The new review status of a board.
 */
class DesignStudioBoardStatusPatchRequestDto extends RequestDto
{
    /** @var string One of the configured status keys */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[Length(max: 32)]
    public string $status;
}
