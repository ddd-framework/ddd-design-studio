'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { ArrowLeft, MapPin, MapPinPlus, MessageSquare, X } from 'lucide-react';
import { type MouseEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { BoardComments, type BoardPin, buildCommentThreads } from '../canvas/BoardComments';
import { buildThemeReference, type ThemeMode } from '../canvas/designTheme';
import {
  type DeviceViewport,
  siteViewportForBoard,
  isPinInViewport,
  isResponsiveBoard,
  viewportWithWidth,
} from '../canvas/deviceViewport';
import { ViewportToolbar, WidthDragHandle } from '../canvas/ViewportControls';
import { useCheckedContentUrl } from '../canvas/useCheckedContentUrl';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { parseSiteFrameMessage, resolveSiteHref, sitePagesOf, startRouteOf } from '../site/siteNavigation';
import { cx } from '../utils/cx';

export interface DesignStudioSiteScreenProps {
  projectKey: string;
  canvasKey: string;
  /** The page's path (`/`, `/pricing`) from the application's route; null / empty = the start page. */
  route: string | null;
}

/**
 * A canvas as a clickable website: the boards with a `route` are its pages, one at a time, full width in the chosen
 * device: the page keeps the device's viewport height and scrolls itself (`vh` units as on the device). Links inside a
 * page open the mapped page (a history entry per page: back / forward work), `#anchors` scroll, external links are
 * not followed, a link without a page says so. Comments are placed on the page as on the canvas (page positions in the
 * viewport they were placed in, the pins move with the page's scroll position; same threads). The look comes from the `theme` / `mode` query
 * parameters, as on the canvas. The page runs the studio's navigation script only (opaque origin, the board's own
 * scripts removed).
 */
export const DesignStudioSiteScreen = ({ projectKey, canvasKey, route }: DesignStudioSiteScreenProps) => {
  const { api, apiBaseUrl, components, notify, paths, router, useCanWrite } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const { Button, Skeleton, StatusCallout } = components;
  const { Link } = router;
  const navigate = router.useNavigate?.();
  const searchParams = router.useSearchParams();
  const writesAllowed = useCanWrite();
  const themeId = searchParams.get('theme');
  const mode: ThemeMode = searchParams.get('mode') === 'dark' ? 'dark' : 'light';
  const themeArgument = themeId ? buildThemeReference(themeId, mode) : undefined;
  const { data, isLoading, error } = api.useCanvasQuery({
    projectKey,
    canvasKey,
    ...(themeArgument ? { theme: themeArgument } : {}),
  });
  const { data: commentsData } = api.useCanvasCommentsQuery({ projectKey, canvasKey });
  const canvas = data?.designStudioCanvasView;
  const boards = useMemo(() => canvas?.boards?.elements ?? [], [canvas]);
  const devices = useMemo(() => canvas?.devices?.elements ?? [], [canvas]);
  const pages = useMemo(() => sitePagesOf(boards), [boards]);
  const currentRoute = startRouteOf(pages, route);
  const board = currentRoute !== null ? (pages.get(currentRoute) ?? null) : null;

  // the viewport: the device picked here (kept while navigating), else the page's widest frame, else its own width
  const [chosenViewport, setChosenViewport] = useState<DeviceViewport | null>(null);
  const viewport: DeviceViewport | null =
    siteViewportForBoard(board, chosenViewport) ??
    (board ? chosenViewport ?? { width: board.width ?? 390, height: board.height ?? 844, deviceKey: null } : null);
  const [pageScrollTop, setPageScrollTop] = useState(0);
  const [showsDeviceFrame, setShowsDeviceFrame] = useState(false);
  const [isCommenting, setIsCommenting] = useState(false);
  const [isPlacingPin, setIsPlacingPin] = useState(false);
  const [pendingPin, setPendingPin] = useState<BoardPin | null>(null);
  const [highlightedCommentId, setHighlightedCommentId] = useState<number | null>(null);
  const [showResolved, setShowResolved] = useState(false);
  const frameRef = useRef<HTMLIFrameElement | null>(null);
  const stageRef = useRef<HTMLDivElement | null>(null);
  const [stageWidth, setStageWidth] = useState(0);

  const boardKey = board?.boardKey ?? null;
  // a new page starts at its top, without a pending pin
  useEffect(() => {
    if (boardKey === null) return;
    setPageScrollTop(0);
    setPendingPin(null);
    setIsPlacingPin(false);
  }, [boardKey]);

  useEffect(() => {
    const stage = stageRef.current;
    if (!stage || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(() => setStageWidth(stage.clientWidth));
    observer.observe(stage);
    setStageWidth(stage.clientWidth);
    return () => observer.disconnect();
  }, []);

  const goToRoute = useCallback(
    (nextRoute: string) => {
      if (!paths.site) return;
      const query = themeId ? `?theme=${encodeURIComponent(themeId)}&mode=${mode}` : '';
      const href = `${paths.site(projectKey, canvasKey, nextRoute)}${query}`;
      if (navigate) navigate(href);
      else if (typeof window !== 'undefined') window.location.assign(href);
    },
    [paths, projectKey, canvasKey, themeId, mode, navigate]
  );

  // the page's navigation script: link clicks, anchors and the page height
  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      if (!frameRef.current || event.source !== frameRef.current.contentWindow) return;
      const message = parseSiteFrameMessage(event.data);
      if (!message || currentRoute === null) return;
      if (message.type === 'scroll') {
        setPageScrollTop(message.top);
        return;
      }
      if (message.type === 'height') return;
      const target = resolveSiteHref(message.href, currentRoute, boards);
      if (target.kind === 'page') {
        if (target.route !== currentRoute) goToRoute(target.route);
      } else if (target.kind === 'external') {
        notify.info(texts.siteExternalLink(target.href));
      } else if (target.kind === 'missing') {
        notify.info(texts.sitePageMissing(target.path));
      }
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  });

  const width = viewport?.width ?? 390;
  const screenHeight = viewport?.height ?? 844;
  const bezelPadding = showsDeviceFrame ? 24 : 0;
  const scale = stageWidth > 0 ? Math.min(1, (stageWidth - 48) / (width + bezelPadding)) : 1;
  const siteHtmlUrl = board?.htmlUrl ? `${board.htmlUrl}&site=1` : null;
  const htmlUrl = useCheckedContentUrl(siteHtmlUrl ? `${apiBaseUrl}${siteHtmlUrl}` : undefined);

  const threads = useMemo(
    () =>
      buildCommentThreads(
        (commentsData?.designStudioComments.elements ?? []).filter(comment => comment.boardKey === boardKey)
      ),
    [commentsData, boardKey]
  );
  const shownViewport = board && isResponsiveBoard(board) ? viewport : null;
  const pinnedThreads = threads.filter(
    thread =>
      thread.pinNumber !== null &&
      (showResolved || !thread.root.isResolved) &&
      isPinInViewport(thread.root, shownViewport)
  );
  const placePin = (event: MouseEvent<HTMLButtonElement>) => {
    const rect = event.currentTarget.getBoundingClientRect();
    setPendingPin({
      x: Math.round((event.clientX - rect.left) / scale),
      y: Math.round((event.clientY - rect.top) / scale + pageScrollTop),
      viewport: shownViewport,
    });
    setIsPlacingPin(false);
    setIsCommenting(true);
  };

  if (error) {
    return (
      <StatusCallout
        tone="destructive"
        title="The site could not be loaded"
        description={normalizeDddError(error, 'Request failed').message}
      />
    );
  }
  if (isLoading || !canvas) return <Skeleton className="h-[70vh] w-full rounded-2xl" />;
  if (currentRoute === null || !board || !viewport) {
    return (
      <StatusCallout
        title="This canvas has no pages"
        description={'A board becomes a page with "route": "/path" in canvas.json or <meta name="design-route" content="/path"> in its file.'}
      />
    );
  }
  const requestedRoute = route ? route : '/';
  const isRequestedRouteMissing = requestedRoute !== currentRoute && route !== null && route !== '';

  return (
    <div className="flex h-[calc(100dvh-4rem)] min-h-0 flex-col overflow-hidden rounded-3xl bg-card ring-1 ring-foreground/5">
      <header className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <Link href={paths.canvas(projectKey, canvasKey)} className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> {canvas.label ?? canvasKey}
        </Link>
        <span className="text-muted-foreground">/</span>
        <select
          aria-label="Page"
          className="h-8 rounded-lg border border-border bg-card px-2 text-sm"
          value={currentRoute}
          onChange={event => goToRoute(event.target.value)}
        >
          {[...pages.entries()].map(([pageRoute, pageBoard]) => (
            <option key={pageRoute} value={pageRoute}>
              {pageRoute} · {pageBoard.title ?? pageBoard.boardKey}
            </option>
          ))}
        </select>
        {isRequestedRouteMissing && (
          <span className="text-xs text-warning">{texts.sitePageMissing(requestedRoute)}</span>
        )}
        <div className="ml-auto flex items-center gap-2">
          <Button
            variant={isPlacingPin ? 'default' : 'outline'}
            size="sm"
            disabled={!writesAllowed}
            aria-pressed={isPlacingPin}
            onClick={() => setIsPlacingPin(placing => !placing)}
          >
            <MapPinPlus /> {isPlacingPin ? 'Click on the page…' : 'Add pin'}
          </Button>
          <Button
            variant={isCommenting ? 'default' : 'outline'}
            size="sm"
            aria-pressed={isCommenting}
            onClick={() => setIsCommenting(open => !open)}
          >
            <MessageSquare /> {threads.filter(thread => !thread.root.isResolved).length}
          </Button>
        </div>
      </header>
      <ViewportToolbar
        devices={devices}
        viewport={viewport}
        hiddenPinCount={0}
        showsDeviceFrame={showsDeviceFrame}
        onShowsDeviceFrameChange={setShowsDeviceFrame}
        onViewportChange={setChosenViewport}
      />
      <div className="flex min-h-0 flex-1">
        <div ref={stageRef} className="flex min-h-0 flex-1 items-start justify-center overflow-auto bg-tint p-6">
          <div style={{ width: (width + bezelPadding) * scale, height: (screenHeight + bezelPadding) * scale }}>
            <div
              className={cx('relative origin-top-left', showsDeviceFrame && 'rounded-[28px] bg-foreground p-3 shadow-surface-raised')}
              style={{ transform: scale !== 1 ? `scale(${scale})` : undefined, width: width + bezelPadding }}
            >
              <div className="relative overflow-hidden bg-card" style={{ width, height: screenHeight }}>
                {htmlUrl && (
                  <iframe
                    key={htmlUrl}
                    ref={frameRef}
                    src={htmlUrl}
                    title={board.title ?? board.boardKey ?? 'Page'}
                    sandbox="allow-scripts"
                    referrerPolicy="no-referrer"
                    width={width}
                    height={screenHeight}
                    className="absolute inset-0 border-0 bg-transparent"
                  />
                )}
                {isPlacingPin && (
                  <button
                    type="button"
                    aria-label="Place the pin here"
                    className="absolute inset-0 z-10 cursor-crosshair bg-brand/10"
                    onClick={placePin}
                  />
                )}
                {pinnedThreads.map(thread => (
                  <button
                    key={String(thread.root.id)}
                    type="button"
                    aria-label={`Comment ${thread.pinNumber}`}
                    onClick={() => {
                      setHighlightedCommentId(Number(thread.root.id));
                      setIsCommenting(true);
                    }}
                    className={cx(
                      'absolute z-20 flex size-7 -translate-x-1/2 -translate-y-full items-center justify-center rounded-full rounded-bl-none text-xs font-bold shadow-surface-raised ring-2 ring-card',
                      thread.root.isResolved ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground',
                      highlightedCommentId === Number(thread.root.id) && 'scale-110 ring-brand'
                    )}
                    style={{ left: thread.root.x ?? 0, top: (thread.root.y ?? 0) - pageScrollTop }}
                  >
                    {thread.pinNumber}
                  </button>
                ))}
                {pendingPin && (
                  <span
                    className="absolute z-20 flex size-7 -translate-x-1/2 -translate-y-full items-center justify-center rounded-full rounded-bl-none bg-brand text-card shadow-surface-raised ring-2 ring-card"
                    style={{ left: pendingPin.x, top: pendingPin.y - pageScrollTop }}
                    aria-hidden
                  >
                    <MapPin className="size-4" />
                  </span>
                )}
              </div>
              <WidthDragHandle
                scale={scale}
                width={width}
                onWidthChange={nextWidth => setChosenViewport(viewportWithWidth(viewport, nextWidth))}
              />
            </div>
          </div>
        </div>
        {isCommenting && (
          <aside className="flex min-h-0 w-full max-w-[400px] flex-col border-l border-border">
            <div className="flex items-center justify-between px-5 pt-4">
              <p className="text-sm font-semibold">{board.title ?? board.boardKey}</p>
              <Button variant="ghost" size="icon" aria-label="Close comments" onClick={() => setIsCommenting(false)}>
                <X />
              </Button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              <BoardComments
                projectKey={projectKey}
                canvasKey={canvasKey}
                board={board}
                threads={threads}
                pendingPin={pendingPin}
                onClearPendingPin={() => setPendingPin(null)}
                highlightedCommentId={highlightedCommentId}
                onHighlightComment={setHighlightedCommentId}
                writesAllowed={writesAllowed}
                showResolved={showResolved}
                onShowResolvedChange={setShowResolved}
                pinHint="“Add pin” marks a spot on the page"
                onCopyThreadReference={() => undefined}
                onSelectViewport={isResponsiveBoard(board) ? setChosenViewport : undefined}
              />
            </div>
          </aside>
        )}
      </div>
    </div>
  );
};
