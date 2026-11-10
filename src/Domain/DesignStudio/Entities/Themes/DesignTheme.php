<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Themes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A look boards can be shown in (e.g. a customer's brand colours), offered by DesignThemeProviderService. Never stored.
 */
class DesignTheme extends ValueObject
{
    /** @var string Mode of a theme: the light look */
    public const string MODE_LIGHT = 'light';

    /** @var string Mode of a theme: the dark look */
    public const string MODE_DARK = 'dark';

    /** @var string|null Id the provider resolves, e.g. `brand:1` (no `:light` / `:dark` suffix) */
    public ?string $themeId = null;

    /** @var string|null Name shown in the theme switcher */
    public ?string $label = null;

    /** @var bool The theme has a dark look of its own */
    public bool $supportsDark = false;
}
