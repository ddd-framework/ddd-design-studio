<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Filters the comments of a canvas.
 */
class DesignStudioCommentsGetRequestDto extends RequestDto
{
    /** @var bool|null true: only comments of threads that are not resolved */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?bool $onlyOpenThreads = null;

    /** @var int|null Only comments with a larger id (polling) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $sinceId = null;
}
