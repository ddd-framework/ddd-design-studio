/**
 * Public surface of the design studio (published as `@mgamadeus/ddd-design-studio`). See README.md.
 *
 * Everything except the canvas screen: this entry does not load the canvas engine. The canvas screen is the second
 * entry, `canvas/index.ts` (published as `@mgamadeus/ddd-design-studio/canvas`).
 */
export {
  type CreateDesignStudioApiOptions,
  createDesignStudioApi,
  DESIGN_STUDIO_TAG,
  type DesignStudioApi,
  type DesignStudioBaseApi,
  type DesignStudioBaseQuery,
} from './api/createDesignStudioApi';
export type * from './api/types';
export {
  type BoardRegion,
  buildBoardDeepLink,
  buildDesignReference,
  DESIGN_REFERENCE_PREFIX,
  type DesignReferenceInput,
} from './canvas/designReference';
export {
  boardCodeMatches,
  boardCodeUrlValue,
  buildBoardCodeLink,
  CODE_PARAM,
  codeBelongsToCanvas,
  findBoardForCode,
  normalizeBoardCode,
} from './canvas/boardCode';
export { buildThemeReference, splitThemeReference, type ThemeLook, type ThemeMode } from './canvas/designTheme';
export {
  DesignStudioProvider,
  type DesignStudioProviderProps,
  useDesignStudio,
  useDesignStudioApi,
  useDesignStudioComponents,
  useDesignStudioNotify,
  useDesignStudioPaths,
  useDesignStudioRouter,
  useDesignStudioTexts,
} from './provider/DesignStudioProvider';
export { DEFAULT_DESIGN_STUDIO_TEXTS, resolveDesignStudioTexts } from './provider/texts';
export type * from './provider/types';
export { DesignStudioOverviewScreen, type DesignStudioOverviewScreenProps } from './screens/DesignStudioOverviewScreen';
export { DesignStudioProjectScreen, type DesignStudioProjectScreenProps } from './screens/DesignStudioProjectScreen';
export { DesignStudioReviewsScreen, type DesignStudioReviewsScreenProps } from './screens/DesignStudioReviewsScreen';
export { cx } from './utils/cx';
