<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One canvas in the studio overview. Never stored.
 */
class DesignStudioCanvasSummary extends ValueObject
{
    /** @var string|null Key of the canvas within the project */
    public ?string $canvasKey = null;

    /** @var string|null What every board code of the canvas starts with (`APP ONB`), null when the canvas has no code */
    public ?string $code = null;

    /** @var string|null Name of the canvas */
    public ?string $label = null;

    /** @var string|null Reference of the theme the canvas names as its usual look */
    public ?string $referenceTheme = null;

    /** @var int Boards in the checkout */
    public int $boardCount = 0;

    /** @var int Comment threads that are not resolved */
    public int $openThreadCount = 0;

    /** @var int Decisions recorded on the canvas */
    public int $decisionCount = 0;
}
