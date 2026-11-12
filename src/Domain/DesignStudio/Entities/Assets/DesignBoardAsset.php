<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Assets;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The bytes of one asset file as delivered: from the canvas folder of the checkout or from a snapshot kept for a
 * commented board version.
 */
class DesignBoardAsset extends ValueObject
{
    /** @var string|null Path of the file below the canvas' `assets/` folder */
    public ?string $assetPath = null;

    /** @var string|null Version of the file: its git blob hash */
    public ?string $contentHash = null;

    /** @var string|null Media type derived from the file extension (`image/webp`) */
    public ?string $mediaType = null;

    /** @var string The file content */
    public string $content = '';
}
