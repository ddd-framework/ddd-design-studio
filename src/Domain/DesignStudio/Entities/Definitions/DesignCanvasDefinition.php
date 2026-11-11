<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Definitions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One canvas of a design project as declared in the config `DesignStudio.projects`: a folder of the running checkout
 * holding `canvas.json` and the board files, optionally narrowed to a subset by file name patterns. Never stored.
 */
class DesignCanvasDefinition extends ValueObject
{
    /** @var string|null Key of the project the canvas belongs to */
    public ?string $projectKey = null;

    /** @var string|null Key of the canvas, unique within its project (URL and review-state key) */
    public ?string $canvasKey = null;

    /** @var string|null Display name */
    public ?string $label = null;

    /** @var string|null Folder relative to the project root, without `..` */
    public ?string $folder = null;

    /** @var string[] fnmatch patterns of board file names belonging to the canvas; empty = every board of canvas.json */
    public array $boardPatterns = [];

    /** @var string[] fnmatch patterns of note keys that are section titles; empty = every note of a title kind */
    public array $sectionPatterns = [];

    /** @var string|null Theme id + mode the canvas is designed in (DesignThemeProviderService), e.g. `brand:2:light` */
    public ?string $referenceTheme = null;

    /** @var string|null Short code of the project (`APP`), null = none; first part of every board code */
    public ?string $projectCode = null;

    /** @var string|null Short code of the canvas (`ONB`), null = the canvas' boards have no code prefix */
    public ?string $code = null;

    /**
     * @return string|null what every board code of the canvas starts with: `APP ONB`, `ONB` without a project code,
     *                     null when the canvas has no code
     */
    public function getCodePrefix(): ?string
    {
        if ($this->code === null) {
            return null;
        }
        return $this->projectCode !== null ? $this->projectCode . ' ' . $this->code : $this->code;
    }
}
