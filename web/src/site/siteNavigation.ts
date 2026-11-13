/**
 * The site view: a canvas as a clickable website. Boards with a `route` are its pages; a link clicked inside a board
 * (reported by the board's navigation script, see the backend's DesignBoardContentService::addSiteNavigationScript;
 * `#anchors` the page scrolls to itself) is resolved here to a page, an external link (not followed) or a missing
 * page. Pure functions, no React
 * (tested in siteNavigation.test.ts).
 */

import type { DesignStudioBoardView } from '../api/types';

/** Where a clicked link leads. */
export type SiteLinkTarget =
  | { kind: 'page'; board: DesignStudioBoardView; route: string; hash: string | null }
  | { kind: 'anchor'; hash: string }
  | { kind: 'external'; href: string }
  | { kind: 'missing'; path: string };

/** A message of the board's navigation script. */
export type SiteFrameMessage =
  | { type: 'navigate'; href: string }
  | { type: 'scroll'; top: number }
  | { type: 'height'; height: number };

/** `/pricing/` → `/pricing`, `pricing` → `/pricing`, `` → `/`; null when it is no plain path. */
export const normalizeSitePath = (path: string): string | null => {
  const trimmed = path.trim();
  if (/[?#]/.test(trimmed) || trimmed.includes('..') || trimmed.includes('//')) return null;
  const segments = trimmed.split('/').filter(segment => segment !== '' && segment !== '.');
  return `/${segments.join('/')}`;
};

/** The pages of a canvas: route → board (boards without a route are no page). */
export const sitePagesOf = (boards: readonly DesignStudioBoardView[]): Map<string, DesignStudioBoardView> => {
  const pages = new Map<string, DesignStudioBoardView>();
  for (const board of boards) {
    if (board.route && !pages.has(board.route)) pages.set(board.route, board);
  }
  return pages;
};

/** The route a site view opens at: the given one when it is a page, else `/`, else the first page. */
export const startRouteOf = (pages: ReadonlyMap<string, DesignStudioBoardView>, route: string | null): string | null => {
  const normalized = route !== null ? normalizeSitePath(route) : null;
  if (normalized !== null && pages.has(normalized)) return normalized;
  if (pages.has('/')) return '/';
  return pages.keys().next().value ?? null;
};

/**
 * Resolves the `href` of a clicked link: `#x` an anchor on the page, `board:<file>` the board with that file name
 * (its route, else no page), `/path` or a relative `path` (relative to the current page's folder) a page by its route,
 * anything with a scheme or `//` external.
 */
export const resolveSiteHref = (
  href: string,
  currentRoute: string,
  boards: readonly DesignStudioBoardView[]
): SiteLinkTarget => {
  const trimmed = href.trim();
  if (trimmed === '' || trimmed.startsWith('#')) return { kind: 'anchor', hash: trimmed };
  if (trimmed.startsWith('board:')) {
    const boardKey = trimmed.slice('board:'.length).split('#')[0];
    const board = boards.find(candidate => candidate.boardKey === boardKey);
    return board?.route
      ? { kind: 'page', board, route: board.route, hash: null }
      : { kind: 'missing', path: trimmed };
  }
  if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed) || trimmed.startsWith('//')) return { kind: 'external', href: trimmed };
  const hashIndex = trimmed.indexOf('#');
  const hash = hashIndex >= 0 ? trimmed.slice(hashIndex) : null;
  const pathWithQuery = hashIndex >= 0 ? trimmed.slice(0, hashIndex) : trimmed;
  const path = pathWithQuery.split('?')[0];
  const absolutePath = path.startsWith('/')
    ? path
    : `${currentRoute.endsWith('/') ? currentRoute : currentRoute.slice(0, currentRoute.lastIndexOf('/') + 1)}${path}`;
  const route = normalizeSitePath(absolutePath);
  const board = route !== null ? sitePagesOf(boards).get(route) : undefined;
  return board && route !== null ? { kind: 'page', board, route, hash } : { kind: 'missing', path: route ?? path };
};

/** The message of the board's navigation script, null for anything else. */
export const parseSiteFrameMessage = (data: unknown): SiteFrameMessage | null => {
  if (typeof data !== 'object' || data === null) return null;
  const message = data as Record<string, unknown>;
  if (message.source !== 'design-studio-site') return null;
  if (message.type === 'navigate' && typeof message.href === 'string') return { type: 'navigate', href: message.href };
  if (message.type === 'scroll' && typeof message.top === 'number' && Number.isFinite(message.top)) {
    return { type: 'scroll', top: Math.max(0, Math.round(message.top)) };
  }
  if (message.type === 'height' && typeof message.height === 'number' && Number.isFinite(message.height)) {
    return { type: 'height', height: Math.max(1, Math.min(100_000, Math.round(message.height))) };
  }
  return null;
};
