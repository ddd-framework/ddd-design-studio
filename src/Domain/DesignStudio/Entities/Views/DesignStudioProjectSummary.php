<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One design project in the studio overview. Never stored.
 */
class DesignStudioProjectSummary extends ValueObject
{
    /** @var string|null Key of the design project */
    public ?string $projectKey = null;

    /** @var string|null Name of the project */
    public ?string $label = null;

    /** @var DesignStudioCanvasSummaries The canvases of the project */
    public DesignStudioCanvasSummaries $canvases;
}
