'use client';

import { useState } from 'react';

import { withoutContentToken } from './canvasLiveUpdate.ts';

/**
 * Delivery URLs that stay the same for the page's lifetime. The content behind a board URL is fixed by its path and
 * query (board, content hash, theme, size); only the content token in it rotates (a new token every 30 min).
 * A new token must never reload an image or iframe that already shows that content, and a board that remounts (pan,
 * zoom level) should get the URL the browser cache already holds. So:
 * - one URL per content (the URL without its token) is kept for the page — the first one seen — and handed to every
 *   element that shows that content, until its token is about to expire (EXPIRY_MARGIN_SECONDS), then the newest one;
 * - an element that already displays its content keeps its URL even past the expiry (it never requests it again).
 * Pure helpers tested in stableContentUrl.test.ts.
 */

/** A token closer than this to its expiry is not handed to elements that still have to load (seconds). */
const EXPIRY_MARGIN_SECONDS = 120;

/** content key (URL without token) → the URL shown for it */
const urlByContentKey = new Map<string, string>();
/** token → its expiry (epoch seconds; 0 = unreadable, treated as expired) */
const expiryByToken = new Map<string, number>();

/** The `expiresAt` claim of the URL's content token (epoch seconds), null when the URL has no token. */
export const contentTokenExpiry = (url: string): number | null => {
  const query = url.split('?')[1];
  if (!query) return null;
  const token = new URLSearchParams(query).get('token');
  if (!token) return null;
  const known = expiryByToken.get(token);
  if (known !== undefined) return known;
  let expiry = 0;
  try {
    const payload = token.split('.')[1] ?? '';
    const json = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/')));
    expiry = Number(json.expiresAt ?? json.exp ?? 0) || 0;
  } catch {
    // not a readable JWT: treated as expired, the newest URL wins
    expiry = 0;
  }
  expiryByToken.set(token, expiry);
  return expiry;
};

/** Whether the URL's token expires within the margin (URLs without a token never expire). */
export const isContentUrlExpiring = (url: string, nowSeconds = Date.now() / 1000): boolean => {
  const expiry = contentTokenExpiry(url);
  return expiry !== null && expiry - EXPIRY_MARGIN_SECONDS <= nowSeconds;
};

/** The page's URL for this content: the one kept so far while its token is still good, else `url` (kept from now). */
export const resolveStableContentUrl = (url: string): string => {
  const contentKey = withoutContentToken(url);
  const kept = urlByContentKey.get(contentKey);
  if (kept !== undefined && (kept === url || !isContentUrlExpiring(kept))) return kept;
  urlByContentKey.set(contentKey, url);
  return url;
};

/**
 * @param url the current delivery URL (from the latest API answer), undefined = nothing to show
 * @param isDisplayed whether the element already shows this content (then its URL is kept even past the expiry)
 * @returns the URL for the element: unchanged as long as the content is the same
 */
export const useStableContentUrl = (url: string | undefined, isDisplayed = false): string | undefined => {
  const [shownUrl, setShownUrl] = useState<string | undefined>(() => (url ? resolveStableContentUrl(url) : undefined));
  if (!url) {
    if (shownUrl !== undefined) setShownUrl(undefined);
    return undefined;
  }
  if (
    shownUrl !== undefined &&
    withoutContentToken(shownUrl) === withoutContentToken(url) &&
    (isDisplayed || !isContentUrlExpiring(shownUrl))
  ) {
    return shownUrl;
  }
  const nextUrl = resolveStableContentUrl(url);
  if (nextUrl !== shownUrl) setShownUrl(nextUrl);
  return nextUrl;
};
