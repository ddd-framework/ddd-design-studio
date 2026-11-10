<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The start page of the studio: projects with their canvases and what the studio is configured with. Never stored.
 */
class DesignStudioOverview extends ValueObject
{
    /** @var DesignStudioProjectSummaries The configured projects */
    public DesignStudioProjectSummaries $projects;

    /** @var string[] The configured status keys in display order; the first is the default */
    public array $statuses = [];

    /** @var bool Thumbnails are rendered and shown */
    public bool $isRendererEnabled = false;

    /** @var bool The application offers themes (looks) for the boards */
    public bool $hasThemes = false;

    /** @var int Days a thread may be idle before it is deleted */
    public int $threadIdleDays = 0;

    /** @var int Threads that will be deleted soon unless something happens in them */
    public int $expiringThreadCount = 0;
}
