<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Answer of a deletion.
 */
class DesignStudioDeleteResponseDto extends RestResponseDto
{
    /** @var bool true when the deletion was carried out */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isDeleted = true;
}
