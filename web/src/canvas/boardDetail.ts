/**
 * Level of detail of the design canvas (render only what is visible). Pure functions, no React: which image a board shows at a zoom, and which boards may mount their live
 * (sandboxed iframe) HTML.
 */

/** A board's place on the canvas, in canvas pixels. */
export interface BoardRect {
  id: string;
  x: number;
  y: number;
  width: number;
  height: number;
}

/** React Flow's viewport: screen = canvas × zoom + (x, y). */
export interface CanvasViewport {
  x: number;
  y: number;
  zoom: number;
}

export interface ScreenSize {
  width: number;
  height: number;
}

/**
 * Image a board shows: the ~120 px thumbnail at far zoom, the 640 px preview (WebP / JPEG) at middle zoom, the full
 * PNG only when the board is drawn wider than the preview can serve sharply.
 */
export type BoardThumbnailVariant = 'small' | 'preview' | 'default';

/** Rendered width (screen px) up to which the small thumbnail is enough (it is 120 px wide). */
export const SMALL_THUMBNAIL_MAX_RENDERED_WIDTH = 130;

/** Rendered width (screen px) up to which the preview is enough (it is at most 640 px wide). */
export const PREVIEW_THUMBNAIL_MAX_RENDERED_WIDTH = 800;

/** A board shows live HTML only when it is rendered at least this share of its native width. */
export const LIVE_MIN_RENDERED_WIDTH_RATIO = 0.6;

/**
 * While boards have no thumbnail in the shown look yet (the renderer is still working, or it is off), they switch to
 * the live HTML earlier.
 */
export const LIVE_MIN_RENDERED_WIDTH_RATIO_WITHOUT_THUMBNAILS = 0.35;

/** At most this many live iframes at once (the ones nearest the viewport centre). */
export const MAX_LIVE_BOARDS = 6;

export const thumbnailVariantForZoom = (zoom: number, boardWidth: number): BoardThumbnailVariant => {
  const renderedWidth = boardWidth * zoom;
  if (renderedWidth <= SMALL_THUMBNAIL_MAX_RENDERED_WIDTH) return 'small';
  return renderedWidth <= PREVIEW_THUMBNAIL_MAX_RENDERED_WIDTH ? 'preview' : 'default';
};

/**
 * The URL a board (or a device frame) shows for a variant, falling back to the next larger one that exists: small →
 * preview → full.
 */
export const thumbnailUrlForVariant = (
  urls: { thumbnailUrl?: string | null; thumbnailPreviewUrl?: string | null; thumbnailSmallUrl?: string | null },
  variant: BoardThumbnailVariant
): string | null => {
  if (variant === 'small') return urls.thumbnailSmallUrl ?? urls.thumbnailPreviewUrl ?? urls.thumbnailUrl ?? null;
  if (variant === 'preview') return urls.thumbnailPreviewUrl ?? urls.thumbnailUrl ?? null;
  return urls.thumbnailUrl ?? null;
};

export const isZoomLiveCapable = (zoom: number, minRatio: number = LIVE_MIN_RENDERED_WIDTH_RATIO): boolean =>
  zoom >= minRatio;

/**
 * The boards that may show live HTML: inside the viewport, rendered at ≥ `minRatio` of their width,
 * nearest the viewport centre first, at most `maxLiveBoards`.
 */
export const selectLiveBoardIds = (
  boards: readonly BoardRect[],
  viewport: CanvasViewport,
  screen: ScreenSize,
  maxLiveBoards: number = MAX_LIVE_BOARDS,
  minRatio: number = LIVE_MIN_RENDERED_WIDTH_RATIO
): Set<string> => {
  if (!isZoomLiveCapable(viewport.zoom, minRatio) || screen.width <= 0 || screen.height <= 0) {
    return new Set();
  }
  const left = -viewport.x / viewport.zoom;
  const top = -viewport.y / viewport.zoom;
  const right = left + screen.width / viewport.zoom;
  const bottom = top + screen.height / viewport.zoom;
  const centreX = (left + right) / 2;
  const centreY = (top + bottom) / 2;
  const visible: { id: string; distance: number }[] = [];
  for (const board of boards) {
    const isInside =
      board.x < right && board.x + board.width > left && board.y < bottom && board.y + board.height > top;
    if (!isInside) continue;
    const distance = Math.hypot(board.x + board.width / 2 - centreX, board.y + Math.min(board.height, 844) / 2 - centreY);
    visible.push({ id: board.id, distance });
  }
  visible.sort((first, second) => first.distance - second.distance);
  return new Set(visible.slice(0, maxLiveBoards).map(entry => entry.id));
};

/** The boards inside the viewport, nearest the viewport centre first (render priority of missing thumbnails). */
export const selectVisibleBoardIds = (
  boards: readonly BoardRect[],
  viewport: CanvasViewport,
  screen: ScreenSize
): string[] => {
  if (screen.width <= 0 || screen.height <= 0 || viewport.zoom <= 0) return [];
  const left = -viewport.x / viewport.zoom;
  const top = -viewport.y / viewport.zoom;
  const right = left + screen.width / viewport.zoom;
  const bottom = top + screen.height / viewport.zoom;
  const centreX = (left + right) / 2;
  const centreY = (top + bottom) / 2;
  return boards
    .filter(board => board.x < right && board.x + board.width > left && board.y < bottom && board.y + board.height > top)
    .map(board => ({ id: board.id, distance: Math.hypot(board.x + board.width / 2 - centreX, board.y + board.height / 2 - centreY) }))
    .sort((first, second) => first.distance - second.distance)
    .map(entry => entry.id);
};
