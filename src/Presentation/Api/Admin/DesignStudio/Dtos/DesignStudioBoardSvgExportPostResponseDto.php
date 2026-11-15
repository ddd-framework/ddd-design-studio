<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Dtos;

use DDD\Domain\DesignStudio\Entities\Views\DesignStudioBoardSvgExportView;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * A board exported as SVG.
 */
class DesignStudioBoardSvgExportPostResponseDto extends RestResponseDto
{
    /** @var DesignStudioBoardSvgExportView The SVG with its sizes and its difference from the raster picture */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public DesignStudioBoardSvgExportView $designStudioBoardSvgExport;
}
