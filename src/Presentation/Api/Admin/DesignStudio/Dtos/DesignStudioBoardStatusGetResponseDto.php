<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatus;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The stored status of a board.
 */
class DesignStudioBoardStatusGetResponseDto extends RestResponseDto
{
    /** @var DesignStudioBoardStatus The status of the board */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioBoardStatus $designStudioBoardStatus;
}
