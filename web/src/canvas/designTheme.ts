/**
 * Theme references of the design studio: `<themeId>` or `<themeId>:<mode>` (mode `light` or `dark`), e.g.
 * `brand:1:dark` — the theme id itself may contain colons, only a trailing `:light` / `:dark` is the mode (same rule
 * as `DesignThemeProviderService::splitThemeReference`). Pure functions, no React (tested in designTheme.test.ts).
 */

export type ThemeMode = 'light' | 'dark';

export interface ThemeLook {
  themeId: string;
  mode: ThemeMode;
}

/** Splits a theme reference; null without one (the boards are shown as authored). */
export const splitThemeReference = (themeReference: string | null | undefined): ThemeLook | null => {
  const reference = (themeReference ?? '').trim();
  if (!reference) return null;
  for (const mode of ['light', 'dark'] as const) {
    if (reference.endsWith(`:${mode}`)) return { themeId: reference.slice(0, -(mode.length + 1)), mode };
  }
  return { themeId: reference, mode: 'light' };
};

/** `<themeId>` for light, `<themeId>:dark` for dark. */
export const buildThemeReference = (themeId: string, mode: ThemeMode): string =>
  mode === 'dark' ? `${themeId}:dark` : themeId;
