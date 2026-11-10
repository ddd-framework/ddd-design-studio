'use client';

import { useEffect, useState } from 'react';

/**
 * Loads a board's delivery URL (HTML) before an iframe shows it. An `<iframe src>` renders whatever the server answers —
 * on a storage error that was the raw error JSON on top of the thumbnail. The URL is fetched first (at most
 * MAX_CONCURRENT_LOADS at once across the canvas, so a zoom does not fire every board at the object storage); only a 2xx
 * answer is handed to the iframe, which then reads it from the browser cache (the endpoint answers `private,
 * immutable`). A failed load leaves the thumbnail in place and is retried with growing delays while the board stays
 * live.
 */
const MAX_CONCURRENT_LOADS = 2;

/** Delays (ms) before the next attempt after a failure; the last one repeats. */
const RETRY_DELAYS_MS = [2000, 5000, 15000, 30000];

/**
 * URLs already loaded successfully in this page, least recently used first (a remount shows them at once; the browser
 * cache holds the bytes). Bounded: it also decides which boards remount their live HTML outside the live set.
 */
const MAX_REMEMBERED_URLS = 24;
const loadedUrls = new Set<string>();

const rememberLoadedUrl = (url: string) => {
  loadedUrls.delete(url);
  loadedUrls.add(url);
  while (loadedUrls.size > MAX_REMEMBERED_URLS) {
    const oldestUrl = loadedUrls.values().next().value;
    if (oldestUrl === undefined) break;
    loadedUrls.delete(oldestUrl);
  }
};

/** Whether this board HTML URL (content hash + theme in its query) was shown live before in this page. */
export const wasContentUrlLoaded = (url: string | undefined): boolean => Boolean(url && loadedUrls.has(url));

let activeLoads = 0;
const waitingLoads: Array<() => void> = [];

const acquireLoadSlot = (): Promise<void> =>
  new Promise(resolve => {
    if (activeLoads < MAX_CONCURRENT_LOADS) {
      activeLoads += 1;
      resolve();
      return;
    }
    waitingLoads.push(() => {
      activeLoads += 1;
      resolve();
    });
  });

const releaseLoadSlot = () => {
  activeLoads -= 1;
  waitingLoads.shift()?.();
};

const loadUrl = async (url: string, signal: AbortSignal): Promise<boolean> => {
  await acquireLoadSlot();
  try {
    if (signal.aborted) return false;
    const response = await fetch(url, { signal, credentials: 'omit', referrerPolicy: 'no-referrer' });
    if (!response.ok) return false;
    // read the whole body so the browser cache holds the complete answer for the iframe
    await response.blob();
    return true;
  } catch {
    // network error or abort: the caller retries while the board is still live
    return false;
  } finally {
    releaseLoadSlot();
  }
};

/**
 * @param url the delivery URL, undefined when nothing should load (board not live)
 * @returns the URL once it answered 2xx, else undefined (show the thumbnail)
 */
export const useCheckedContentUrl = (url: string | undefined): string | undefined => {
  const [checkedUrl, setCheckedUrl] = useState<string | undefined>(() =>
    url && loadedUrls.has(url) ? url : undefined
  );

  useEffect(() => {
    if (!url) {
      setCheckedUrl(undefined);
      return;
    }
    if (loadedUrls.has(url)) {
      rememberLoadedUrl(url);
      setCheckedUrl(url);
      return;
    }
    setCheckedUrl(undefined);
    const abortController = new AbortController();
    let retryTimer: ReturnType<typeof setTimeout> | undefined;
    let attempt = 0;
    const tryLoad = async () => {
      const isLoaded = await loadUrl(url, abortController.signal);
      if (abortController.signal.aborted) return;
      if (isLoaded) {
        rememberLoadedUrl(url);
        setCheckedUrl(url);
        return;
      }
      const delay = RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)];
      attempt += 1;
      retryTimer = setTimeout(tryLoad, delay);
    };
    void tryLoad();
    return () => {
      abortController.abort();
      if (retryTimer) clearTimeout(retryTimer);
    };
  }, [url]);

  return checkedUrl;
};

/**
 * Retries a failed `<img>` (a thumbnail answered with an error): the image is hidden, and after a growing delay it is
 * mounted again under a new key, which requests the URL once more (error answers are not cached).
 * @returns the key for the `<img>`, whether it failed (hide it until the retry) and its `onError` handler
 */
export const useImageRetry = (url: string | undefined) => {
  const [attempt, setAttempt] = useState(0);
  const [isFailed, setIsFailed] = useState(false);
  const [previousUrl, setPreviousUrl] = useState(url);
  // a new URL (another theme, a new token window) starts over (state reset during render, no effect needed)
  if (url !== previousUrl) {
    setPreviousUrl(url);
    setAttempt(0);
    setIsFailed(false);
  }

  useEffect(() => {
    if (!isFailed) return;
    const retryTimer = setTimeout(
      () => {
        setIsFailed(false);
        setAttempt(previousAttempt => previousAttempt + 1);
      },
      RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)]
    );
    return () => clearTimeout(retryTimer);
  }, [isFailed, attempt]);

  return { imageKey: `${url ?? ''}#${attempt}`, isFailed, onError: () => setIsFailed(true) };
};
