<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Themes\DesignTheme;
use DDD\Domain\DesignStudio\Entities\Themes\DesignThemes;
use DDD\Infrastructure\Services\Service;

/**
 * The looks boards can be shown in. A theme is a `<style data-design-theme>` element with CSS variables that the
 * studio places first in a board's `<head>`, so the board's own styles (`var(--accent, #…)`) pick it up. The default
 * knows no themes: boards are shown as they are. An application with themes (one per brand, per tenant, …)
 * subclasses this service and registers the subclass under this class' service id.
 *
 * A theme reference is `<themeId>` or `<themeId>:<mode>` (mode light or dark), e.g. `brand:1:dark`.
 */
class DesignThemeProviderService extends Service
{
    /**
     * @param string|null $searchText narrows the list for a switcher with many themes
     * @return DesignThemes the themes offered to the theme switcher
     */
    public function findThemes(?string $searchText = null): DesignThemes
    {
        return new DesignThemes();
    }

    /**
     * @param string $themeId
     * @return DesignTheme|null null when the provider does not know the theme
     */
    public function getTheme(string $themeId): ?DesignTheme
    {
        return null;
    }

    /**
     * @param string $themeId
     * @param string $mode DesignTheme::MODE_*
     * @return string|null the `<style data-design-theme>` element, null = unknown theme (the board is shown as it is)
     */
    public function getThemeStyle(string $themeId, string $mode): ?string
    {
        return null;
    }

    /**
     * @param string|null $themeReference `<themeId>` or `<themeId>:<mode>`
     * @return string|null the theme's `<style>` element, null without a reference or for an unknown theme
     */
    public function getThemeStyleForReference(?string $themeReference): ?string
    {
        if ($themeReference === null || trim($themeReference) === '') {
            return null;
        }
        [$themeId, $mode] = static::splitThemeReference($themeReference);
        return $this->getThemeStyle($themeId, $mode);
    }

    /**
     * @param string $themeReference `<themeId>` or `<themeId>:<mode>`
     * @return string[] theme id and mode (light when the reference names none)
     */
    public static function splitThemeReference(string $themeReference): array
    {
        $themeReference = trim($themeReference);
        foreach ([DesignTheme::MODE_LIGHT, DesignTheme::MODE_DARK] as $mode) {
            if (str_ends_with($themeReference, ':' . $mode)) {
                return [substr($themeReference, 0, -strlen(':' . $mode)), $mode];
            }
        }
        return [$themeReference, DesignTheme::MODE_LIGHT];
    }

    /**
     * @param string|null $themeStyle the `<style>` element of a theme, null = no theme
     * @return string identifies the look: themes with the same CSS share it (and with it their thumbnails)
     */
    public static function computeThemeHash(?string $themeStyle): string
    {
        return hash('sha256', $themeStyle ?? '');
    }
}
