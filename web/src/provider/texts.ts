import type { DesignStudioTexts } from './types';

/** The English defaults of the texts an application can replace through `environment.texts`. */
export const DEFAULT_DESIGN_STUDIO_TEXTS: DesignStudioTexts = {
  referencesCopied: count => (count > 1 ? `References copied (${count})` : 'Reference copied'),
  boardsUpdated: count => (count === 1 ? '1 board updated' : `${count} boards updated`),
  showUpdatedBoard: 'Show',
  canvasUpToDate: 'Canvas is up to date',
  reload: 'Reload (R)',
  thumbnailsFailed: 'Thumbnails failed · try again',
  thumbnailsWaitingForRenderer: themeLabel => `Thumbnails for ${themeLabel} are waiting for the renderer`,
  thumbnailsRendering: themeLabel => `Thumbnails for ${themeLabel} are being rendered`,
  thumbnailsFallbackThemeLabel: 'this look',
  thumbnailsMissing: count =>
    count === 1
      ? '1 board has no thumbnail in this look yet; it appears as soon as it is rendered.'
      : `${count} boards have no thumbnail in this look yet; they appear as soon as they are rendered.`,
  thumbnailsLastError: error => `Last error: ${error}`,
  themeWithoutDarkMode: 'This theme has no dark mode',
  thumbnailMissing: 'No thumbnail yet',
  referenceNotePlaceholder: 'What should change? (optional, goes into the reference)',
  boardCodeNotFound: code => `No board has the code ${code}`,
  boardLinkCopied: code => `Link to ${code} copied`,
  copyBoardLink: 'Copy link',
};

/** The defaults with the application's replacements on top; an entry that is missing or `undefined` keeps its default. */
export const resolveDesignStudioTexts = (texts: Partial<DesignStudioTexts> | undefined): DesignStudioTexts => {
  if (!texts) return DEFAULT_DESIGN_STUDIO_TEXTS;
  const defined = Object.fromEntries(Object.entries(texts).filter(([, value]) => value !== undefined));
  return { ...DEFAULT_DESIGN_STUDIO_TEXTS, ...defined };
};
