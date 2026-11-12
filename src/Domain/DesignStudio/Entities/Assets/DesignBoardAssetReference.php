<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Assets;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One file of a canvas' `assets/` folder that a board references, with the version the board shows.
 */
class DesignBoardAssetReference extends ValueObject
{
    /** @var string|null Path of the file below the canvas' `assets/` folder (`photos/start-hero.webp`) */
    public ?string $assetPath = null;

    /** @var string|null Version of the file: its git blob hash; null when the file is missing */
    public ?string $contentHash = null;

    /**
     * @return string
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->assetPath);
    }
}
