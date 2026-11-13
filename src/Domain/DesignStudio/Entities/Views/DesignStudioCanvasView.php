<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectorySections;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\DesignStudio\Entities\Devices\DesignDevices;

/**
 * A canvas as the studio page shows it: sections and boards read from the checkout, joined with the review state
 * of the database. Never stored.
 */
class DesignStudioCanvasView extends ValueObject
{
    /** @var string|null Key of the design project */
    public ?string $projectKey = null;

    /** @var string|null Key of the canvas within the project */
    public ?string $canvasKey = null;

    /** @var string|null What every board code of the canvas starts with (`APP ONB`), null when the canvas has no code */
    public ?string $code = null;

    /** @var string|null Name of the canvas */
    public ?string $label = null;

    /** @var string|null Reference of the theme the delivery URLs and thumbnails are in, null = no theme */
    public ?string $themeReference = null;

    /** @var string|null Reference of the theme the canvas names as its usual look */
    public ?string $referenceTheme = null;

    /** @var DesignDirectorySections Sections, top to bottom */
    public DesignDirectorySections $sections;

    /** @var DesignStudioBoardViews Boards, top to bottom and left to right */
    public DesignStudioBoardViews $boards;

    /** @var string[] Problems found while reading the folder (missing files, oversized boards) */
    public array $warnings = [];

    /** @var string[] The configured status keys in display order */
    public array $statuses = [];

    /** @var string|null Fingerprint of boards, comments and statuses (DesignStudioCanvasViewsService::getRevision) */
    public ?string $revision = null;

    /** @var bool Thumbnails are rendered and shown */
    public bool $isRendererEnabled = false;

    /** @var int Pictures (fixed boards and device frames of responsive boards) without a thumbnail in the requested look */
    public int $missingThumbnailCount = 0;

    /** @var DesignDevices The device presets responsive boards can be shown in (picker of focus view and present mode) */
    public DesignDevices $devices;

    /** @var string|null Status of the newest render request for the look, null without one */
    public ?string $renderRequestStatus = null;

    /** @var string|null Last renderer problem of that request */
    public ?string $renderRequestError = null;
}
