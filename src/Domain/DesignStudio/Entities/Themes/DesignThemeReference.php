<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Themes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A theme reference taken apart: `<themeId>` or `<themeId>:<mode>`, e.g. `brand:1:dark`.
 */
class DesignThemeReference extends ValueObject
{
    /** @var string|null Id the theme provider resolves (no `:light` / `:dark` suffix) */
    public ?string $themeId = null;

    /** @var string Mode of the look, light when the reference names none */
    public string $mode = DesignTheme::MODE_LIGHT;

    /**
     * @param string $themeReference `<themeId>` or `<themeId>:<mode>`
     * @return DesignThemeReference
     */
    public static function fromString(string $themeReference): DesignThemeReference
    {
        $designThemeReference = new DesignThemeReference();
        $themeReference = trim($themeReference);
        $designThemeReference->themeId = $themeReference;
        foreach ([DesignTheme::MODE_LIGHT, DesignTheme::MODE_DARK] as $mode) {
            if (str_ends_with($themeReference, ':' . $mode)) {
                $designThemeReference->themeId = substr($themeReference, 0, -strlen(':' . $mode));
                $designThemeReference->mode = $mode;
                break;
            }
        }
        return $designThemeReference;
    }
}
