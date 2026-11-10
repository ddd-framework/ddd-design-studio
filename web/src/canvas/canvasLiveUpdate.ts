/**
 * Canvas viewport in the URL (`vp=<x>,<y>,<zoom>`) and the in-place board update of a refetched canvas (reload button,
 * live update). Pure functions, no React (tested in canvasLiveUpdate.test.ts).
 */

/** A React Flow viewport: pan in screen px, zoom factor. */
export interface CanvasViewport {
  x: number;
  y: number;
  zoom: number;
}

/** The smallest / largest zoom a `vp` parameter may carry (the canvas' own limits are 0.005 … 2.5). */
const VIEWPORT_MIN_ZOOM = 0.001;
const VIEWPORT_MAX_ZOOM = 10;

/** `vp` value: pan rounded to whole px, zoom to 4 decimals without trailing zeros (`-1234,560,0.0762`). */
export const formatViewportParam = ({ x, y, zoom }: CanvasViewport): string =>
  `${Math.round(x)},${Math.round(y)},${Number(zoom.toFixed(4))}`;

/** Parses a `vp` value; null when missing or malformed (the canvas then fits its first view). */
export const parseViewportParam = (value: string | null | undefined): CanvasViewport | null => {
  if (!value) return null;
  const parts = value.split(',');
  if (parts.length !== 3 || parts.some(part => part.trim() === '')) return null;
  const [x, y, zoom] = parts.map(Number);
  if (![x, y, zoom].every(Number.isFinite) || zoom < VIEWPORT_MIN_ZOOM || zoom > VIEWPORT_MAX_ZOOM) return null;
  return { x, y, zoom };
};

/** The board fields the in-place update compares (a subset of DesignStudioBoardView). */
export interface LiveBoard {
  boardKey?: string | null;
  /** Version of the board file (git blob hash). */
  contentHash?: string | null;
  htmlUrl?: string | null;
  thumbnailUrl?: string | null;
  thumbnailSmallUrl?: string | null;
}

/** A delivery URL without its content token (the token rotates every 30 min, the content behind it does not). */
export const withoutContentToken = (url: string | null | undefined): string => {
  if (!url) return '';
  const [path, query = ''] = url.split('?');
  const params = new URLSearchParams(query);
  params.delete('token');
  const rest = params.toString();
  return rest ? `${path}?${rest}` : path;
};

/** The first characters of a content hash, as shown for a board's version (`3f9c2ab`). */
export const shortContentHash = (contentHash: string | null | undefined): string => (contentHash ?? '').slice(0, 7);

/**
 * The delivery URL to show: the previous one while it shows the same content (same URL without the token) and
 * `keepPreviousUrls`, else the new one (another version, a thumbnail that arrived, a stale token).
 */
const pickDeliveryUrl = (
  previous: string | null | undefined,
  next: string | null | undefined,
  keepPreviousUrls: boolean
): string | null | undefined =>
  keepPreviousUrls && previous && withoutContentToken(previous) === withoutContentToken(next) ? previous : next;

/** Shallow equality of two board objects (every own key, values by identity). */
const isSameBoard = (previous: object, next: object): boolean => {
  const previousEntries = Object.entries(previous);
  if (previousEntries.length !== Object.keys(next).length) return false;
  const nextRecord = next as Record<string, unknown>;
  return previousEntries.every(([key, value]) => key in nextRecord && Object.is(nextRecord[key], value));
};

export interface BoardMergeResult<T extends LiveBoard> {
  /** The boards to render: unchanged boards keep their previous object (no re-render, no iframe / image reload). */
  boards: T[];
  /** Keys of the boards whose file changed (another content hash) or that are new, in the order of `nextBoards`. */
  changedBoardKeys: string[];
  /** Whether anything differs from `previousBoards` (false → `boards` is `previousBoards` itself). */
  isChanged: boolean;
}

/**
 * Merges a refetched board list into the shown one, board by board (`boardKey`). A delivery URL that shows the same
 * content keeps its previous value while `keepPreviousUrls` (the previous content token is still valid), so a
 * thumbnail or live iframe is not reloaded just because the token rotated; a board keeps its previous object when
 * nothing else changed either. A URL with other content (a thumbnail that was rendered meanwhile, a new version) is
 * taken over in place. Only a new board or another `contentHash` counts as changed — a thumbnail arriving for the
 * same version does not.
 */
export const mergeRefetchedBoards = <T extends LiveBoard>(
  previousBoards: readonly T[] | null,
  nextBoards: readonly T[],
  keepPreviousUrls: boolean
): BoardMergeResult<T> => {
  if (previousBoards === null) return { boards: [...nextBoards], changedBoardKeys: [], isChanged: true };
  const previousByKey = new Map(previousBoards.map(board => [String(board.boardKey), board]));
  const changedBoardKeys: string[] = [];
  const boards = nextBoards.map(next => {
    const previous = previousByKey.get(String(next.boardKey));
    if (!previous || (previous.contentHash ?? null) !== (next.contentHash ?? null)) {
      changedBoardKeys.push(String(next.boardKey));
      return next;
    }
    const candidate: T = {
      ...next,
      htmlUrl: pickDeliveryUrl(previous.htmlUrl, next.htmlUrl, keepPreviousUrls),
      thumbnailUrl: pickDeliveryUrl(previous.thumbnailUrl, next.thumbnailUrl, keepPreviousUrls),
      thumbnailSmallUrl: pickDeliveryUrl(previous.thumbnailSmallUrl, next.thumbnailSmallUrl, keepPreviousUrls),
    };
    return isSameBoard(previous, candidate) ? previous : candidate;
  });
  const isChanged =
    boards.length !== previousBoards.length || boards.some((board, index) => board !== previousBoards[index]);
  return { boards: isChanged ? boards : (previousBoards as T[]), changedBoardKeys, isChanged };
};
