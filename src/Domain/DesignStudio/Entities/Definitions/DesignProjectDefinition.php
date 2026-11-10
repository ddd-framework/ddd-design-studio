<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Definitions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One design project as declared in the config `DesignStudio.projects`. Never stored.
 */
class DesignProjectDefinition extends ValueObject
{
    /** @var string|null Key of the project (URL and review-state key) */
    public ?string $projectKey = null;

    /** @var string|null Display name */
    public ?string $label = null;

    /** @var string|null URL segment below the studio route */
    public ?string $path = null;

    /** @var DesignCanvasDefinitions|null The project's canvases */
    public ?DesignCanvasDefinitions $canvasDefinitions = null;
}
