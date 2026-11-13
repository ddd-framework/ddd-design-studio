<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Devices;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * A device a responsive board is shown in: a viewport size in CSS pixels (config `DesignStudio.devices`).
 */
class DesignDevice extends ValueObject
{
    /** @var string A desktop or laptop screen */
    public const string KIND_DESKTOP = 'DESKTOP';

    /** @var string A tablet */
    public const string KIND_TABLET = 'TABLET';

    /** @var string A phone */
    public const string KIND_PHONE = 'PHONE';

    /** @var string|null Key of the preset, e.g. `iphone-16` */
    public ?string $deviceKey = null;

    /** @var string|null Name shown in the picker */
    public ?string $label = null;

    /** @var int Viewport width in CSS pixels */
    public int $width = 0;

    /** @var int Viewport height in CSS pixels */
    public int $height = 0;

    /** @var string|null KIND_* */
    #[Choice(choices: [self::KIND_DESKTOP, self::KIND_TABLET, self::KIND_PHONE])]
    public ?string $kind = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->deviceKey);
    }
}
