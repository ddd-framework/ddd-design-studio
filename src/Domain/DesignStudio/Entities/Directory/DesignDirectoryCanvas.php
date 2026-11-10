<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Directory;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A canvas as read from its folder in the running checkout: sections and boards with their content hashes, plus what
 * could not be read. Never stored.
 */
class DesignDirectoryCanvas extends ValueObject
{
    /** @var string|null Key of the project */
    public ?string $projectKey = null;

    /** @var string|null Key of the canvas */
    public ?string $canvasKey = null;

    /** @var string|null Display name: the definition label, else the canvas.json title */
    public ?string $title = null;

    /** @var DesignDirectorySections|null Sections that own at least one board */
    public ?DesignDirectorySections $sections = null;

    /** @var DesignDirectoryBoards|null Boards of the canvas */
    public ?DesignDirectoryBoards $boards = null;

    /** @var string[] Problems found while reading (missing folder, missing or oversized board files) */
    public array $warnings = [];
}
