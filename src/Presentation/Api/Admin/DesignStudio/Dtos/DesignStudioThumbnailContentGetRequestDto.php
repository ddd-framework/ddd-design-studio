<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignStudioThumbnail;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * A rendered thumbnail.
 */
class DesignStudioThumbnailContentGetRequestDto extends RequestDto
{
    /** @var string Key of the thumbnail from the canvas response */
    #[Parameter(in: Parameter::PATH, required: true)]
    #[Length(max: 64)]
    public string $thumbnailKey;

    /** @var string|null Content token from the canvas response */
    #[Parameter(in: Parameter::QUERY, required: true)]
    #[Length(max: 2000)]
    public ?string $token = null;

    /** @var string|null full (default) or small (far zoom) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: [DesignStudioThumbnail::SIZE_FULL, DesignStudioThumbnail::SIZE_SMALL])]
    public ?string $size = null;
}
