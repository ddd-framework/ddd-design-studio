/**
 * What the thumbnail pill of the canvas toolbar shows for a canvas view: progress while a render request runs, the
 * reason when it failed, "n of m, k failed" when some pictures could not be produced. Pure functions, no React
 * (tested in thumbnailRenderState.test.ts).
 */

/** Statuses of a render request that still has work to do. */
export const OPEN_RENDER_STATUSES: ReadonlySet<string> = new Set(['QUEUED', 'RUNNING', 'WAITING_FOR_RENDERER']);

/** The canvas view fields the pill reads (a subset of DesignStudioCanvasView). */
export interface ThumbnailRenderCanvas {
  isRendererEnabled?: boolean;
  missingThumbnailCount?: number;
  thumbnailPictureCount?: number;
  renderRequestStatus?: string | null;
  renderRequestError?: string | null;
  renderRequestFailedBoards?: { elements?: { renderTargetKey?: string | null; error?: string | null }[] } | null;
  isThemeStyleUnstable?: boolean;
}

/**
 * - `pending`: thumbnails are missing and no request is open (one is filed on its own once per board version; the
 *   pill files one on a click)
 * - `rendering` / `waiting`: a request is open (rendering, or waiting for the renderer)
 * - `failed`: the request ended without any picture; `reason` says why
 * - `partlyFailed`: the request ended with pictures it could not produce (`failedCount`, `reason`)
 * - `themeUnstable`: the theme provider answers another style on each call; nothing can be rendered
 */
export type ThumbnailRenderStateKind = 'pending' | 'rendering' | 'waiting' | 'failed' | 'partlyFailed' | 'themeUnstable';

export interface ThumbnailRenderState {
  kind: ThumbnailRenderStateKind;
  /** Pictures that have a thumbnail */
  renderedCount: number;
  /** Pictures the look has or needs a thumbnail for */
  pictureCount: number;
  /** Pictures the last request could not produce (still missing) */
  failedCount: number;
  /** Why the last request failed or is incomplete, null without a reason */
  reason: string | null;
  /** The failed pictures, one line each (`<renderTargetKey>: <error>`) */
  failedBoards: { renderTargetKey: string; error: string }[];
  /** A click on the pill files a new request */
  canRetry: boolean;
  /** The spinner turns */
  isBusy: boolean;
}

/** The pill of a canvas view; null when there is nothing to show (no renderer, every picture there). */
export const describeThumbnailRenderState = (canvas: ThumbnailRenderCanvas | null | undefined): ThumbnailRenderState | null => {
  if (!canvas?.isRendererEnabled) return null;
  const missingCount = Math.max(0, canvas.missingThumbnailCount ?? 0);
  const pictureCount = Math.max(missingCount, canvas.thumbnailPictureCount ?? 0);
  const failedBoards = (canvas.renderRequestFailedBoards?.elements ?? []).map(failure => ({
    renderTargetKey: failure.renderTargetKey ?? '',
    error: failure.error ?? '',
  }));
  const reason = canvas.renderRequestError?.trim() ? canvas.renderRequestError.trim() : null;
  const base = {
    renderedCount: pictureCount - missingCount,
    pictureCount,
    failedCount: failedBoards.length,
    reason,
    failedBoards,
  };
  if (canvas.isThemeStyleUnstable) {
    return { ...base, kind: 'themeUnstable', canRetry: false, isBusy: false };
  }
  if (missingCount === 0) return null;
  const status = canvas.renderRequestStatus ?? null;
  if (status !== null && OPEN_RENDER_STATUSES.has(status)) {
    return {
      ...base,
      kind: status === 'WAITING_FOR_RENDERER' ? 'waiting' : 'rendering',
      canRetry: false,
      isBusy: true,
    };
  }
  if (status === 'FAILED') {
    return { ...base, kind: 'failed', canRetry: true, isBusy: false };
  }
  if (status === 'DONE' && (failedBoards.length > 0 || reason !== null)) {
    return { ...base, kind: 'partlyFailed', canRetry: true, isBusy: false };
  }
  // no request yet, or a finished one without problems while boards changed since
  return { ...base, kind: 'pending', canRetry: true, isBusy: false };
};

/**
 * A request is filed on its own only when thumbnails are missing and nobody has to look at a problem first: never
 * while one is open, never after a failed or incomplete one (the pill offers the retry), never for an unstable theme.
 */
export const shouldFileRenderRequestAutomatically = (canvas: ThumbnailRenderCanvas | null | undefined): boolean =>
  describeThumbnailRenderState(canvas)?.kind === 'pending';
