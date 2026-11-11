'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import {
  ClipboardCopy,
  ImageDown,
  Link2,
  ListFilter,
  LoaderCircle,
  Maximize,
  Maximize2,
  MessageSquare,
  Minimize,
  Moon,
  PanelRightClose,
  PanelRightOpen,
  RefreshCw,
  Sun,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { DesignStudioBoardView, DesignStudioComment } from '../api/types';
import { useDesignStudio, useDesignStudioComponents, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import type { DesignStudioShell } from '../provider/types';
import { cx } from '../utils/cx';
import { BoardComments, buildCommentThreads, type CommentThread } from './BoardComments';
import { BoardFocusView } from './BoardFocusView';
import { BoardStatusChips } from './BoardStatusChips';
import { buildBoardCodeLink, CODE_PARAM, findBoardForCode, normalizeBoardCode } from './boardCode';
import { LIVE_MIN_RENDERED_WIDTH_RATIO, LIVE_MIN_RENDERED_WIDTH_RATIO_WITHOUT_THUMBNAILS } from './boardDetail';
import { boardStatusLabel } from './boardStatus';
import { computeCanvasLayout } from './canvasLayout';
import {
  type CanvasViewport,
  formatViewportParam,
  mergeRefetchedBoards,
  parseViewportParam,
  shortContentHash,
} from './canvasLiveUpdate';
import { isContentUrlExpiring } from './stableContentUrl';
import {
  buildBoardDeepLink,
  buildDesignReference,
  copyTextToClipboard,
  type DesignReferenceInput,
} from './designReference';
import { boardNodeId, CanvasViewContext, type CanvasViewState, sectionNodeId } from './canvasView';
import { buildThemeReference, splitThemeReference, type ThemeMode } from './designTheme';
import { type CanvasFitRequest, type CanvasTool, DesignCanvasFlow } from './DesignCanvasFlow';

/** URL parameters of the canvas state (search, section, status, theme, theme mode, viewport `x,y,zoom`). */
const CANVAS_STATE_KEYS = ['q', 'section', 'status', 'theme', 'mode', 'vp'] as const;
/** Keys that are not "filters" (filter badge counts section and status only). */
const UNCOUNTED_CANVAS_STATE_KEYS: readonly string[] = ['q', 'theme', 'mode', 'vp'];

/** Live update: how often the canvas revision is polled while the tab is visible (ms). */
const LIVE_UPDATE_POLL_MS = 15_000;

/** Poll interval of the canvas while a thumbnail render request runs (images swap in as they arrive). */
const THUMBNAIL_RENDER_POLL_MS = 3_000;

/** Render request statuses that are still being worked on. */
const OPEN_RENDER_STATUSES: ReadonlySet<string> = new Set(['QUEUED', 'RUNNING', 'WAITING_FOR_RENDERER']);

/** At most this many boards on screen are named as priority of a render request. */
const MAX_PRIORITY_BOARDS = 200;

/** Toast id of the live update (a newer update replaces the toast). */
const LIVE_UPDATE_TOAST_ID = 'design-canvas-live-update';

/** The boards as shown: merged in place from each canvas response (canvasLiveUpdate.ts). */
interface ShownBoards {
  /** The response elements they were merged from (undefined = nothing loaded yet). */
  source: readonly DesignStudioBoardView[] | undefined;
  /** Theme reference of that response ('' = no theme): another one starts over like a first load. */
  themeReference: string;
  boards: DesignStudioBoardView[];
  /** When the delivery URLs of unchanged boards were taken over (ms since epoch). */
  urlsAdoptedAt: number;
  /** Keys of the boards with a new version (or new) in the last merge; empty for the first load and a new theme. */
  changedBoardKeys: string[];
  /** Counts merges (effects key on it). */
  serial: number;
}

const usePageVisible = (): boolean => {
  const [isVisible, setIsVisible] = useState(true);
  useEffect(() => {
    const update = () => setIsVisible(document.visibilityState === 'visible');
    update();
    document.addEventListener('visibilitychange', update);
    return () => document.removeEventListener('visibilitychange', update);
  }, []);
  return isVisible;
};

/** URL parameter of the board open in focus view (deep links from the overview and from comments). */
const BOARD_PARAM = 'board';

const TOOL_BUTTON_CLASS =
  'relative flex size-8 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-tint hover:text-foreground disabled:pointer-events-none disabled:opacity-40 motion-reduce:transition-none';

/** Room the canvas toolbar takes above the content when a view is fitted (px). */
const CANVAS_TOOLBAR_HEIGHT = 96;

const TOOLBAR_GROUP_CLASS = 'surface-glass-strong flex items-center gap-1 rounded-2xl p-1 ring-1 ring-foreground/5';

/** Without a shell sidebar there is nothing to collapse: "already collapsed", the toggle does nothing. */
const NO_SIDEBAR = [true, () => undefined] as const;
const useNoSidebar = () => NO_SIDEBAR;

/**
 * The canvas route wants the room: the shell's sidebar is collapsed while the canvas is open and returns to how it was
 * when the page is left or the tab is closed / reloaded (only if this page collapsed it).
 */
const useCollapsedSidebarWhileMounted = (useSidebarCollapsed: DesignStudioShell['useSidebarCollapsed'] = useNoSidebar) => {
  const [isCollapsed, toggleCollapsed] = useSidebarCollapsed();
  const collapsedByCanvasRef = useRef(false);
  const toggleRef = useRef(toggleCollapsed);
  toggleRef.current = toggleCollapsed;
  // biome-ignore lint/correctness/useExhaustiveDependencies: once on mount: collapse; on unmount / tab close: restore
  useEffect(() => {
    if (!isCollapsed) {
      collapsedByCanvasRef.current = true;
      toggleRef.current();
    }
    const restore = () => {
      if (!collapsedByCanvasRef.current) return;
      collapsedByCanvasRef.current = false;
      toggleRef.current();
    };
    window.addEventListener('pagehide', restore);
    return () => {
      window.removeEventListener('pagehide', restore);
      restore();
    };
  }, []);
};

export interface DesignStudioCanvasScreenProps {
  projectKey: string;
  canvasKey: string;
}

/** The selected board's side panel on the canvas: status, current version, comments, open in focus view. */
const SelectedBoardPanel = ({
  projectKey,
  canvasKey,
  board,
  statuses,
  sectionTitle,
  threads,
  writesAllowed,
  onOpen,
  onCopyLink,
  onStatusChanged,
  onCopyThreadReference,
}: {
  projectKey: string;
  canvasKey: string;
  board: DesignStudioBoardView;
  statuses: readonly string[];
  sectionTitle: string | null;
  threads: CommentThread[];
  writesAllowed: boolean;
  onOpen: () => void;
  /** Copies the code link of the board; absent when the board has no code. */
  onCopyLink?: () => void;
  onStatusChanged: (status: string) => void;
  onCopyThreadReference: (thread: CommentThread) => void;
}) => {
  const { Button } = useDesignStudioComponents();
  const texts = useDesignStudioTexts();
  const [showResolved, setShowResolved] = useState(false);
  return (
    <div className="space-y-5">
      <div className="space-y-1">
        {board.code && <p className="text-sm font-semibold tabular-nums text-foreground/70">{board.code}</p>}
        <h2 className="text-title-card font-semibold break-words">{board.title}</h2>
        <p className="text-xs text-muted-foreground">
          {sectionTitle ? `${sectionTitle} · ` : ''}
          {board.boardKey}
        </p>
      </div>
      <BoardStatusChips
        projectKey={projectKey}
        canvasKey={canvasKey}
        board={board}
        statuses={statuses}
        writesAllowed={writesAllowed}
        onStatusChanged={onStatusChanged}
      />
      <div className="flex gap-2">
        <Button variant="outline" className="flex-1" onClick={onOpen}>
          <Maximize2 /> Open focus view
        </Button>
        {onCopyLink && (
          <Button variant="outline" onClick={onCopyLink} aria-label={`${texts.copyBoardLink}: ${board.code ?? ''}`}>
            <Link2 /> {texts.copyBoardLink}
          </Button>
        )}
      </div>
      {board.contentHash && (
        <p className="text-xs text-muted-foreground" title={`Content hash of the board file: ${board.contentHash}`}>
          Version <span className="font-mono">{shortContentHash(board.contentHash)}</span>
        </p>
      )}
      <BoardComments
        projectKey={projectKey}
        canvasKey={canvasKey}
        board={board}
        threads={threads}
        pendingPin={null}
        onClearPendingPin={() => undefined}
        highlightedCommentId={null}
        onHighlightComment={() => undefined}
        writesAllowed={writesAllowed}
        showResolved={showResolved}
        onShowResolvedChange={setShowResolved}
        pinHint="open the focus view to pin it to a spot"
        onCopyThreadReference={onCopyThreadReference}
      />
    </div>
  );
};

/**
 * Design studio canvas: every board of a canvas laid out per section (a bold title
 * line, then rows of nine boards with a header each), a toolbar on the canvas (select / hand, theme + light / dark
 * mode, comments panel, present, zoom), search and filters by section and status, a collapsible comments panel and
 * the focus view. Rendering stays proportional to what is on screen (boardDetail.ts):
 * only nodes in the viewport mount, far zoom uses the 120 px thumbnails, live HTML only for ≤ 6 large boards after a
 * pan / zoom ends.
 */
export const DesignStudioCanvasScreen = ({ projectKey, canvasKey }: DesignStudioCanvasScreenProps) => {
  const {
    api,
    components,
    notify,
    router,
    shell,
    statuses: statusDefinitions,
    storage,
    useCanWrite,
  } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const {
    Button,
    Combobox,
    Popover,
    PopoverContent,
    PopoverTrigger,
    QuickFilterPills,
    SearchField,
    Skeleton,
    StatusCallout,
    Tooltip,
    TooltipContent,
    TooltipTrigger,
  } = components;
  const searchParams = router.useSearchParams();
  const writeSearchParams = router.useSearchParamsWriter();
  const writesAllowed = useCanWrite();
  const isPageVisible = usePageVisible();

  const [statusOverrides, setStatusOverrides] = useState<ReadonlyMap<string, string>>(new Map());
  const [liveBoardIds, setLiveBoardIds] = useState<ReadonlySet<string>>(new Set());
  const [selectedBoardId, setSelectedBoardId] = useState<string | null>(null);
  const [selectedBoardIds, setSelectedBoardIds] = useState<ReadonlySet<string>>(new Set());
  const [focusedBoardId, setFocusedBoardId] = useState<string | null>(null);
  const [presentSectionId, setPresentSectionId] = useState<string | null>(null);
  const [fitRequest, setFitRequest] = useState<CanvasFitRequest | null>(null);
  // pan by default; the area-select tool is opt-in and kept only for this page visit
  const [tool, setTool] = useState<CanvasTool>('hand');
  // a code link shows the board's panel: open from the start, so opening it does not re-pack the layout after the fit
  const [isCommentsPanelOpen, setIsCommentsPanelOpen] = useState(
    () => Boolean(searchParams.get(CODE_PARAM)) || storage.read('commentsPanelOpen') !== '0'
  );
  // Search, filters, theme and theme mode: in the URL (source of truth), saved per canvas in this tab's
  // sessionStorage and restored when the URL has none of them (the application's URL state, `router.useUrlState`).
  // Defaults (no search / filter, the canvas' reference theme and its mode) are never written.
  const canvasState = router.useUrlState(CANVAS_STATE_KEYS, {
    stateId: `designCanvas.${projectKey}.${canvasKey}`,
    debounceMs: 250,
    uncounted: UNCOUNTED_CANVAS_STATE_KEYS,
  });
  const search = canvasState.values.q;
  const sectionFilter = canvasState.values.section;
  const statusFilter = canvasState.values.status;
  const chosenThemeId = canvasState.values.theme || null;
  const chosenMode: ThemeMode | null =
    canvasState.values.mode === 'dark' || canvasState.values.mode === 'light' ? canvasState.values.mode : null;
  const { setValue: setCanvasStateValue } = canvasState;
  const setSearch = useCallback((value: string) => setCanvasStateValue('q', value), [setCanvasStateValue]);
  const setSectionFilter = useCallback((value: string) => setCanvasStateValue('section', value), [setCanvasStateValue]);
  const setStatusFilter = useCallback((value: string) => setCanvasStateValue('status', value), [setCanvasStateValue]);
  const [isFullscreen, setIsFullscreen] = useState(false);
  // aspect ratio of the canvas area: the layout packs the sections for it ("Fit all" fills the view)
  const canvasAreaRef = useRef<HTMLDivElement>(null);
  const [canvasAspectRatio, setCanvasAspectRatio] = useState<number | null>(null);
  useCollapsedSidebarWhileMounted(shell?.useSidebarCollapsed);
  const debouncedSearch = canvasState.debounced.q;
  // The viewport as saved (`?vp=`, or this tab's saved canvas state): the first view returns there instead of fitting.
  // A code link (`?code=`) at load: the first view goes to that board instead of the saved place.
  const [codeAtLoad] = useState(() => searchParams.get(CODE_PARAM));
  const [initialViewport] = useState<CanvasViewport | null>(() =>
    codeAtLoad ? null : parseViewportParam(canvasState.values.vp)
  );
  // A filter present at load with a saved viewport: the saved place wins over fitting the matches once.
  const [hasInitialFilterWithViewport] = useState(
    () =>
      initialViewport !== null &&
      Boolean(canvasState.values.q.trim() || canvasState.values.section || canvasState.values.status)
  );
  const currentViewportRef = useRef<string | null>(initialViewport ? formatViewportParam(initialViewport) : null);
  const commitViewport = useCallback(
    (viewport: CanvasViewport) => {
      const value = formatViewportParam(viewport);
      currentViewportRef.current = value;
      setCanvasStateValue('vp', value);
    },
    [setCanvasStateValue]
  );

  // Projects, canvases and whether the application offers themes at all (one cached request for the whole studio).
  const { data: overviewData, isError: isOverviewError } = api.useOverviewQuery();
  const overview = overviewData?.designStudioOverview;
  const projectSummary = overview?.projects?.elements?.find(project => project.projectKey === projectKey);
  const canvasSummary = projectSummary?.canvases?.elements?.find(item => item.canvasKey === canvasKey);
  const hasThemes = Boolean(overview?.hasThemes);
  const {
    data: themesData,
    isLoading: isLoadingThemes,
    isError: isThemesError,
  } = api.useThemesQuery({}, { skip: !hasThemes });
  const themes = useMemo(() => themesData?.designThemes.elements ?? [], [themesData]);

  // The look: the canvas' reference theme by default (no `theme` parameter: the backend picks it; without a reference
  // theme the boards are shown as authored), else the chosen theme `<themeId>` / `<themeId>:dark`. Dark only when the
  // theme has a dark look of its own.
  const isDefaultLook = chosenThemeId === null && chosenMode === null;
  // another look needs the theme list (does it support dark?) and, for a mode alone, the canvas' reference theme
  const isLookResolved =
    isDefaultLook ||
    ((overviewData !== undefined || isOverviewError) && (!hasThemes || themesData !== undefined || isThemesError));
  // the canvas' reference theme: from the loaded canvas, before that from the overview (same configuration)
  const [loadedReferenceTheme, setLoadedReferenceTheme] = useState<string | null | undefined>(undefined);
  const referenceTheme = loadedReferenceTheme !== undefined ? loadedReferenceTheme : canvasSummary?.referenceTheme;
  const referenceLook = useMemo(() => splitThemeReference(referenceTheme), [referenceTheme]);
  const referenceThemeId = referenceLook?.themeId ?? null;
  const themeId = chosenThemeId ?? referenceThemeId;
  const selectedTheme = themes.find(theme => theme.themeId === themeId);
  const supportsDark = Boolean(selectedTheme?.supportsDark);
  const defaultMode: ThemeMode = themeId !== null && themeId === referenceThemeId ? (referenceLook?.mode ?? 'light') : 'light';
  const requestedMode: ThemeMode = chosenMode ?? defaultMode;
  const effectiveMode: ThemeMode = requestedMode === 'dark' && (isDefaultLook || supportsDark) ? 'dark' : 'light';
  const themeArgument = isDefaultLook || themeId === null ? undefined : buildThemeReference(themeId, effectiveMode);
  const canvasQueryArgument = useMemo(
    () => ({ projectKey, canvasKey, ...(themeArgument ? { theme: themeArgument } : {}) }),
    [projectKey, canvasKey, themeArgument]
  );

  // While a thumbnail render request runs, the canvas itself is polled so the images swap in as they arrive.
  const [isRenderPolling, setIsRenderPolling] = useState(false);
  const {
    data,
    currentData: currentCanvasData,
    isLoading,
    error,
    isFetching: isFetchingCanvas,
    refetch: refetchCanvas,
  } = api.useCanvasQuery(canvasQueryArgument, {
    skip: !isLookResolved,
    pollingInterval: isPageVisible && isRenderPolling ? THUMBNAIL_RENDER_POLL_MS : 0,
  });
  const { data: commentsData, refetch: refetchComments } = api.useCanvasCommentsQuery({ projectKey, canvasKey });
  // Live update: a cheap revision is polled while the tab is visible; it changes with every
  // board file, comment and status, and a change refetches the canvas (changed boards update in place) and the comments.
  const { currentData: currentRevisionData, refetch: refetchRevision } = api.useCanvasRevisionQuery(
    canvasQueryArgument,
    {
      skip: !isLookResolved,
      pollingInterval: isPageVisible ? LIVE_UPDATE_POLL_MS : 0,
    }
  );

  // `data` stays the previous look's canvas while another theme loads (the boards stay on screen until it answers)
  const canvas = data?.designStudioCanvasView;
  // the canvas in the requested look (undefined while that look is still loading)
  const currentCanvas = currentCanvasData?.designStudioCanvasView;
  if (canvas && (canvas.referenceTheme ?? null) !== loadedReferenceTheme) {
    setLoadedReferenceTheme(canvas.referenceTheme ?? null);
  }
  // the look the boards on screen are really in (the reference block and the deep links name it)
  const shownLook = useMemo(() => splitThemeReference(canvas?.themeReference), [canvas?.themeReference]);
  const sections = useMemo(() => canvas?.sections?.elements ?? [], [canvas]);
  const statuses = useMemo(() => canvas?.statuses ?? overview?.statuses ?? [], [canvas?.statuses, overview?.statuses]);
  const defaultStatus = statuses[0] ?? null;
  const statusFilterOptions = useMemo(
    () => statuses.map(status => ({ value: status, label: boardStatusLabel(statusDefinitions, status) })),
    [statuses, statusDefinitions]
  );
  // Each canvas response is merged into the shown boards: unchanged boards keep their object and delivery URLs (no
  // re-render, no reload), changed ones take the new version's URLs — in place, the view does not move. The first
  // response of another theme starts over like a first load: every board has new URLs, none of them "changed".
  const canvasBoards = canvas?.boards?.elements;
  const [shownBoards, setShownBoards] = useState<ShownBoards>({
    source: undefined,
    themeReference: '',
    boards: [],
    urlsAdoptedAt: 0,
    changedBoardKeys: [],
    serial: 0,
  });
  if (canvasBoards && canvasBoards !== shownBoards.source) {
    const now = Date.now();
    const themeReference = canvas?.themeReference ?? '';
    const isFirstLoad = shownBoards.source === undefined || themeReference !== shownBoards.themeReference;
    // unchanged boards keep their object (and URLs) while the shown content token is good; the elements keep their
    // src across a token change anyway (stableContentUrl.ts), so a later switch only re-renders, never reloads
    const shownTokenUrl = shownBoards.boards.find(board => board.htmlUrl)?.htmlUrl;
    const keepPreviousUrls = !isFirstLoad && (!shownTokenUrl || !isContentUrlExpiring(shownTokenUrl));
    const merged = mergeRefetchedBoards(isFirstLoad ? null : shownBoards.boards, canvasBoards, keepPreviousUrls);
    setShownBoards({
      source: canvasBoards,
      themeReference,
      boards: merged.boards,
      urlsAdoptedAt: keepPreviousUrls ? shownBoards.urlsAdoptedAt : now,
      changedBoardKeys: merged.changedBoardKeys,
      serial: shownBoards.serial + 1,
    });
  }
  const shownBoardList = shownBoards.boards;
  const boards = useMemo<DesignStudioBoardView[]>(
    () =>
      shownBoardList.map(board => {
        const status = statusOverrides.get(boardNodeId(board.boardKey ?? ''));
        return status ? { ...board, status } : board;
      }),
    [shownBoardList, statusOverrides]
  );
  const boardsByNodeId = useMemo(
    () => new Map(boards.map(board => [boardNodeId(board.boardKey ?? ''), board])),
    [boards]
  );
  const sectionTitleByKey = useMemo(
    () => new Map(sections.map(section => [section.sectionKey ?? '', section.title ?? ''])),
    [sections]
  );

  // Layout: computed from sections and board order only (status changes do not move anything).
  const layout = useMemo(() => {
    const sectionKeys = new Set(sections.map(section => section.sectionKey ?? ''));
    return computeCanvasLayout(
      sections.map(section => ({
        id: section.sectionKey ?? '',
        title: section.title ?? '',
        displayOrder: section.displayOrder ?? 0,
      })),
      shownBoardList.map(board => ({
        id: boardNodeId(board.boardKey ?? ''),
        sectionId: board.sectionKey && sectionKeys.has(board.sectionKey) ? board.sectionKey : null,
        width: board.width ?? 390,
        height: board.height ?? 844,
        displayOrder: board.displayOrder ?? 0,
      })),
      canvasAspectRatio ?? undefined
    );
  }, [sections, shownBoardList, canvasAspectRatio]);

  // Measure the canvas area (and follow big resizes: a change under 15 % keeps the layout).
  const hasCanvas = Boolean(canvas);
  // biome-ignore lint/correctness/useExhaustiveDependencies: the canvas area mounts with the canvas data (`hasCanvas`)
  useEffect(() => {
    const canvasArea = canvasAreaRef.current;
    if (!canvasArea) return;
    const measure = () => {
      if (canvasArea.clientWidth === 0 || canvasArea.clientHeight === 0) return;
      const aspectRatio = canvasArea.clientWidth / Math.max(1, canvasArea.clientHeight - CANVAS_TOOLBAR_HEIGHT);
      setCanvasAspectRatio(current =>
        current === null || Math.abs(aspectRatio / current - 1) > 0.15 ? Math.round(aspectRatio * 10) / 10 : current
      );
    };
    measure();
    const resizeObserver = new ResizeObserver(measure);
    resizeObserver.observe(canvasArea);
    return () => resizeObserver.disconnect();
  }, [hasCanvas]);

  // Thumbnails: the canvas view carries one set of URLs for the requested look and says how many boards have none
  // yet. Missing ones are asked for with a render request (boards on screen first); while it is open the canvas is
  // polled fast, so the images swap in as they arrive.
  const isRendererEnabled = Boolean(canvas?.isRendererEnabled);
  const missingThumbnailCount = canvas?.missingThumbnailCount ?? 0;
  const renderStatus = canvas?.renderRequestStatus ?? null;
  const isRenderOpen = renderStatus !== null && OPEN_RENDER_STATUSES.has(renderStatus);
  useEffect(() => setIsRenderPolling(isRenderOpen), [isRenderOpen]);
  const [requestThumbnailRendering] = api.useRequestThumbnailRenderingMutation();
  const visibleBoardIdsRef = useRef<string[]>([]);
  // stable: the canvas re-evaluates its live boards whenever this callback changes
  const rememberVisibleBoardIds = useCallback((visibleBoardIds: string[]) => {
    visibleBoardIdsRef.current = visibleBoardIds;
  }, []);
  const boardsByNodeIdRef = useRef(boardsByNodeId);
  boardsByNodeIdRef.current = boardsByNodeId;
  // Asked for once per look and board version (`<theme>|<boardKey>@<contentHash>`): a board that changed later gets
  // its thumbnail too, a board the renderer could not draw is not asked for again on its own. A request that is
  // already open (by anyone) is not filed again; a failed one can be retried from the toolbar chip.
  const requestedThumbnailsRef = useRef<Set<string>>(new Set());
  const fileRenderRequest = useCallback(
    (isRetry: boolean) => {
      if (!writesAllowed || !currentCanvas) return;
      const missingKeys = (currentCanvas.boards?.elements ?? [])
        .filter(board => !board.thumbnailUrl)
        .map(board => `${themeArgument ?? ''}|${board.boardKey ?? ''}@${board.contentHash ?? ''}`);
      if (missingKeys.length === 0) return;
      if (!isRetry && missingKeys.every(key => requestedThumbnailsRef.current.has(key))) return;
      for (const key of missingKeys) requestedThumbnailsRef.current.add(key);
      const priorityBoardKeys = visibleBoardIdsRef.current
        .map(nodeId => boardsByNodeIdRef.current.get(nodeId)?.boardKey)
        .filter((boardKey): boardKey is string => Boolean(boardKey))
        .slice(0, MAX_PRIORITY_BOARDS);
      // the answer is not needed: the request invalidates the canvas query, which then carries the request status
      void requestThumbnailRendering({
        projectKey,
        canvasKey,
        ...(themeArgument ? { theme: themeArgument } : {}),
        body: {
          theme: themeArgument ?? null,
          priorityBoardKeys,
        },
      });
    },
    [writesAllowed, currentCanvas, themeArgument, projectKey, canvasKey, requestThumbnailRendering]
  );
  useEffect(() => {
    // only for the look that is loaded (never from the previous theme's numbers while another one loads)
    if (!currentCanvas?.isRendererEnabled || !currentCanvas.missingThumbnailCount) return;
    const currentStatus = currentCanvas.renderRequestStatus ?? null;
    if (currentStatus === 'FAILED' || (currentStatus !== null && OPEN_RENDER_STATUSES.has(currentStatus))) return;
    fileRenderRequest(false);
  }, [currentCanvas, fileRenderRequest]);
  const themeOptions = useMemo(
    () => [
      // without a reference theme the default look is the boards as authored
      ...(referenceThemeId === null ? [{ value: '', label: 'As authored' }] : []),
      ...themes.map(theme => ({ value: theme.themeId ?? '', label: theme.label || theme.themeId || 'Theme' })),
    ],
    [themes, referenceThemeId]
  );
  const shownThemeLabel = themes.find(theme => theme.themeId === shownLook?.themeId)?.label ?? texts.thumbnailsFallbackThemeLabel;

  const commentsByBoardNodeId = useMemo(() => {
    const comments = new Map<string, DesignStudioComment[]>();
    for (const comment of commentsData?.designStudioComments.elements ?? []) {
      const nodeId = boardNodeId(comment.boardKey ?? '');
      const boardComments = comments.get(nodeId) ?? [];
      boardComments.push(comment);
      comments.set(nodeId, boardComments);
    }
    return comments;
  }, [commentsData]);
  const openThreadCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const [nodeId, comments] of commentsByBoardNodeId) {
      const openCount = comments.filter(
        comment => comment.parentDesignStudioCommentId == null && !comment.isResolved
      ).length;
      if (openCount > 0) counts.set(nodeId, openCount);
    }
    return counts;
  }, [commentsByBoardNodeId]);
  const openThreadTotal = useMemo(
    () => [...openThreadCounts.values()].reduce((sum, count) => sum + count, 0),
    [openThreadCounts]
  );

  const matchingBoardIds = useMemo(() => {
    const searchText = debouncedSearch.trim().toLowerCase();
    // `w2`, `ONB-W2`, `APP ONB-W2` find the board by its code
    const searchCode = normalizeBoardCode(searchText);
    if (!searchText && !sectionFilter && !statusFilter) return null;
    const matching = new Set<string>();
    for (const board of boards) {
      if (sectionFilter && (board.sectionKey ?? '') !== sectionFilter) continue;
      if (statusFilter && board.status !== statusFilter) continue;
      if (
        searchText &&
        !(board.title ?? '').toLowerCase().includes(searchText) &&
        !(board.boardKey ?? '').toLowerCase().includes(searchText) &&
        !(searchCode && normalizeBoardCode(board.code ?? '').includes(searchCode))
      ) {
        continue;
      }
      matching.add(boardNodeId(board.boardKey ?? ''));
    }
    return matching;
  }, [boards, debouncedSearch, sectionFilter, statusFilter]);

  // Focus navigation order: the presented section, else the layout order (the matches while a filter is active).
  const layoutOrder = useMemo(() => layout.sections.flatMap(section => section.boardIds), [layout]);
  const navigationOrder = useMemo(() => {
    if (presentSectionId) {
      return layout.sections.find(section => section.id === presentSectionId)?.boardIds ?? [];
    }
    return layoutOrder.filter(nodeId => matchingBoardIds === null || matchingBoardIds.has(nodeId));
  }, [layout, layoutOrder, matchingBoardIds, presentSectionId]);

  const fitKeyRef = useRef(0);
  const requestFit = useCallback((nodeIds: string[]) => {
    fitKeyRef.current += 1;
    setFitRequest({ nodeIds, key: fitKeyRef.current });
  }, []);

  // Fit the view to the matches whenever the filter changes (a section alone fits its row) — only when the filter
  // itself changes, never because the matches were recomputed (a status change, a live update), and not for the filter
  // a reload brings along with its saved viewport.
  const filterKey = matchingBoardIds === null ? null : `${debouncedSearch.trim()}|${sectionFilter}|${statusFilter}`;
  const fittedFilterKeyRef = useRef<string | null>(null);
  const skipInitialFilterFitRef = useRef(hasInitialFilterWithViewport);
  useEffect(() => {
    if (filterKey === null || matchingBoardIds === null) {
      fittedFilterKeyRef.current = null;
      return;
    }
    if (matchingBoardIds.size === 0 || fittedFilterKeyRef.current === filterKey) return;
    fittedFilterKeyRef.current = filterKey;
    if (skipInitialFilterFitRef.current) {
      skipInitialFilterFitRef.current = false;
      return;
    }
    requestFit(
      sectionFilter && !debouncedSearch.trim() && !statusFilter ? [sectionNodeId(sectionFilter)] : [...matchingBoardIds]
    );
  }, [filterKey, matchingBoardIds, sectionFilter, debouncedSearch, statusFilter, requestFit]);

  const openBoard = useCallback(
    (nodeId: string) => {
      setSelectedBoardId(nodeId);
      setFocusedBoardId(nodeId);
      const board = boardsByNodeId.get(nodeId);
      if (board?.boardKey) writeSearchParams({ [BOARD_PARAM]: board.boardKey });
    },
    [boardsByNodeId, writeSearchParams]
  );
  const openBoardFromCanvas = useCallback(
    (nodeId: string) => {
      setPresentSectionId(null);
      openBoard(nodeId);
    },
    [openBoard]
  );
  const closeFocus = useCallback(() => {
    setFocusedBoardId(null);
    setPresentSectionId(null);
    writeSearchParams({ [BOARD_PARAM]: null });
  }, [writeSearchParams]);
  const presentSection = useCallback(
    (sectionId: string) => {
      const firstBoardId = layout.sections.find(section => section.id === sectionId)?.boardIds[0];
      if (!firstBoardId) return;
      setPresentSectionId(sectionId);
      openBoard(firstBoardId);
    },
    [layout, openBoard]
  );

  // Deep link: ?board=<boardKey> opens that board once the canvas is loaded.
  const boardParam = searchParams.get(BOARD_PARAM);
  const isDeepLinkHandledRef = useRef(false);
  useEffect(() => {
    if (isDeepLinkHandledRef.current || !boardParam || boards.length === 0) return;
    isDeepLinkHandledRef.current = true;
    const nodeId = boardNodeId(boardParam);
    if (!boardsByNodeId.has(nodeId)) return;
    setSelectedBoardId(nodeId);
    setFocusedBoardId(nodeId);
    // a saved viewport (reload, a link with `vp`) keeps its place behind the focus view
    if (!initialViewport) requestFit([nodeId]);
  }, [boardParam, boards.length, boardsByNodeId, requestFit, initialViewport]);

  // Code link: ?code=<board code> (`APP-ONB-W2`, `ONB-W2`, `W2`) moves the view to that board, selects it and shows
  // its panel. The code at load is the first view itself (initialFitNodeIds); a later one (an in-app link) fits now.
  // The parameter is removed once handled: a reload keeps the place the user moved to.
  const codeParam = searchParams.get(CODE_PARAM);
  const handledCodeRef = useRef<string | null>(null);
  useEffect(() => {
    if (!codeParam) {
      // handled and removed: the same code may come again (another link) and is then handled anew
      if (handledCodeRef.current !== null) handledCodeRef.current = '';
      return;
    }
    if (boards.length === 0 || handledCodeRef.current === codeParam) return;
    const isFirstView = handledCodeRef.current === null && codeParam === codeAtLoad;
    handledCodeRef.current = codeParam;
    writeSearchParams({ [CODE_PARAM]: null });
    const board = findBoardForCode(boards, codeParam);
    if (!board?.boardKey) {
      notify.error(texts.boardCodeNotFound(codeParam));
      return;
    }
    const nodeId = boardNodeId(board.boardKey);
    setSelectedBoardId(nodeId);
    setSelectedBoardIds(new Set([nodeId]));
    if (isFirstView) return;
    if (isCommentsPanelOpen) {
      requestFit([nodeId]);
    } else {
      // opening the panel narrows the canvas and re-packs the layout: fit once the new layout is there
      pendingCodeFitRef.current = nodeId;
      setIsCommentsPanelOpen(true);
    }
  }, [codeParam, codeAtLoad, boards, isCommentsPanelOpen, writeSearchParams, notify, texts, requestFit]);
  const pendingCodeFitRef = useRef<string | null>(null);
  useEffect(() => {
    const nodeId = pendingCodeFitRef.current;
    if (!nodeId || layout.sections.length === 0) return;
    pendingCodeFitRef.current = null;
    requestFit([nodeId]);
  }, [layout, requestFit]);

  const stepFocus = useCallback(
    (step: number) => {
      if (!focusedBoardId || navigationOrder.length === 0) return;
      const index = navigationOrder.indexOf(focusedBoardId);
      openBoard(navigationOrder[(index + step + navigationOrder.length) % navigationOrder.length]);
    },
    [focusedBoardId, navigationOrder, openBoard]
  );

  const setBoardStatus = useCallback((nodeId: string, status: string) => {
    setStatusOverrides(overrides => new Map(overrides).set(nodeId, status));
  }, []);

  const toggleCommentsPanel = () => {
    setIsCommentsPanelOpen(isOpen => {
      storage.write('commentsPanelOpen', isOpen ? '0' : '1');
      return !isOpen;
    });
  };

  // Selection: a click selects one board (the side panel shows it), shift / cmd / ctrl click toggles it in the
  // selection, a marquee (select tool) selects the boards inside; a pane click clears it.
  const selectBoard = useCallback((nodeId: string, isAdditive: boolean) => {
    setSelectedBoardId(nodeId);
    setSelectedBoardIds(current => {
      if (!isAdditive) return new Set([nodeId]);
      const next = new Set(current);
      if (next.has(nodeId)) next.delete(nodeId);
      else next.add(nodeId);
      return next;
    });
  }, []);
  const selectBoards = useCallback((nodeIds: string[], isAdditive: boolean) => {
    setSelectedBoardIds(current => new Set([...(isAdditive ? current : []), ...nodeIds]));
    setSelectedBoardId(nodeIds[0] ?? null);
  }, []);
  const clearSelection = useCallback(() => {
    setSelectedBoardId(null);
    setSelectedBoardIds(new Set());
  }, []);

  // Design references for agents (designReference.ts): one block per board, copied as plain text.
  const projectName = projectSummary?.label ?? projectKey;
  const canvasName = canvas?.label ?? canvasSummary?.label ?? canvasKey;
  const shownThemeReference = canvas?.themeReference ?? null;
  const buildReferenceFor = useCallback(
    (nodeId: string, extra: Partial<DesignReferenceInput> = {}): string | null => {
      const board = boardsByNodeId.get(nodeId);
      if (!board?.boardKey || typeof window === 'undefined') return null;
      return buildDesignReference({
        projectName,
        canvasName,
        projectKey,
        canvasKey,
        boardTitle: board.title ?? board.boardKey,
        boardKey: board.boardKey,
        boardCode: board.code ?? null,
        contentHash: board.contentHash ?? null,
        themeReference: shownThemeReference,
        url: buildBoardDeepLink(
          window.location.href,
          board.boardKey,
          shownLook?.themeId ?? null,
          shownLook?.mode ?? 'light',
          currentViewportRef.current
        ),
        ...extra,
      });
    },
    [boardsByNodeId, projectName, canvasName, projectKey, canvasKey, shownThemeReference, shownLook]
  );
  const copyReferences = useCallback(async (blocks: (string | null)[]) => {
    const text = blocks.filter((block): block is string => block !== null).join('\n\n');
    if (!text) return;
    if (await copyTextToClipboard(text)) {
      notify.success(texts.referencesCopied(blocks.length));
    } else {
      notify.error('Could not copy to the clipboard');
    }
  }, [notify, texts]);
  const copySelectedReferences = useCallback(() => {
    const nodeIds = layoutOrder.filter(nodeId => selectedBoardIds.has(nodeId));
    void copyReferences(nodeIds.map(nodeId => buildReferenceFor(nodeId)));
  }, [layoutOrder, selectedBoardIds, copyReferences, buildReferenceFor]);
  const copyThreadReference = useCallback(
    (thread: CommentThread) =>
      void copyReferences([
        buildReferenceFor(boardNodeId(thread.root.boardKey ?? ''), {
          comment: {
            id: thread.root.id ?? '',
            x: thread.root.x ?? null,
            y: thread.root.y ?? null,
            lines: [thread.root, ...thread.replies].map(comment => `${comment.authorName ?? 'Unknown'}: ${comment.text ?? ''}`),
          },
        }),
      ]),
    [copyReferences, buildReferenceFor]
  );

  const copyBoardLink = useCallback(
    async (board: DesignStudioBoardView) => {
      if (!board.code || typeof window === 'undefined') return;
      const link = buildBoardCodeLink(window.location.href, board.code, shownLook?.themeId ?? null, shownLook?.mode ?? 'light');
      if (await copyTextToClipboard(link)) {
        notify.success(texts.boardLinkCopied(board.code));
      } else {
        notify.error('Could not copy to the clipboard');
      }
    },
    [shownLook, notify, texts]
  );

  // Live update: one revision for boards, comments and statuses — a change refetches the canvas and the comments.
  // The first revision of a look (first load, another theme) is only remembered.
  const seenRevisionRef = useRef<{ look: string; revision: string } | null>(null);
  const revision = currentRevisionData?.revision;
  const revisionLook = themeArgument ?? '';
  useEffect(() => {
    if (!revision) return;
    const seen = seenRevisionRef.current;
    seenRevisionRef.current = { look: revisionLook, revision };
    if (!seen || seen.look !== revisionLook || seen.revision === revision) return;
    void refetchCanvas();
    void refetchComments();
  }, [revision, revisionLook, refetchCanvas, refetchComments]);
  // Back on the tab: check right away instead of waiting for the next poll.
  useEffect(() => {
    if (isPageVisible && seenRevisionRef.current) void refetchRevision();
  }, [isPageVisible, refetchRevision]);

  // Changed boards (live update or reload): a toast names them with a link to the first one in canvas order.
  const showBoard = useCallback(
    (nodeId: string) => {
      setSelectedBoardId(nodeId);
      setSelectedBoardIds(new Set([nodeId]));
      requestFit([nodeId]);
    },
    [requestFit]
  );
  const layoutOrderRef = useRef(layoutOrder);
  layoutOrderRef.current = layoutOrder;
  // biome-ignore lint/correctness/useExhaustiveDependencies: once per merge (`serial`), not when the callbacks change
  useEffect(() => {
    const changedBoardKeys = shownBoards.changedBoardKeys;
    if (changedBoardKeys.length === 0) return;
    const changedNodeIds = new Set(changedBoardKeys.map(boardKey => boardNodeId(boardKey)));
    const firstNodeId =
      layoutOrderRef.current.find(nodeId => changedNodeIds.has(nodeId)) ?? boardNodeId(changedBoardKeys[0]);
    notify.info(texts.boardsUpdated(changedBoardKeys.length), {
      id: LIVE_UPDATE_TOAST_ID,
      action: { label: texts.showUpdatedBoard, onClick: () => showBoard(firstNodeId) },
    });
  }, [shownBoards.serial]);

  // Reload (R): canvas, comments and revision again; only changed boards reload, the view, selection, filters, theme
  // and the focus view stay.
  const shownBoardsRef = useRef(shownBoards);
  shownBoardsRef.current = shownBoards;
  const reloadCanvas = useCallback(async () => {
    // nothing to reload before the canvas query started (the look is still being resolved)
    if (shownBoardsRef.current.source === undefined) return;
    const serialBefore = shownBoardsRef.current.serial;
    const [canvasResult] = await Promise.all([refetchCanvas(), refetchComments(), refetchRevision()]);
    const reloadedBoards = canvasResult.data?.designStudioCanvasView.boards?.elements;
    const shown = shownBoardsRef.current;
    if (!reloadedBoards || (shown.serial !== serialBefore && shown.changedBoardKeys.length > 0)) return;
    // the merge announces changed boards itself; a reload without any says so
    if (mergeRefetchedBoards(shown.boards, reloadedBoards, true).changedBoardKeys.length === 0) {
      notify.info(texts.canvasUpToDate, { id: LIVE_UPDATE_TOAST_ID });
    }
  }, [refetchCanvas, refetchComments, refetchRevision, notify, texts]);
  const reloadCanvasRef = useRef(reloadCanvas);
  reloadCanvasRef.current = reloadCanvas;

  const canvasView = useMemo<CanvasViewState>(
    () => ({
      liveBoardIds,
      selectedBoardId,
      selectedBoardIds,
      matchingBoardIds,
      openThreadCounts,
      defaultStatus,
      isHandTool: tool === 'hand',
      onSelectBoard: selectBoard,
      onCopyReference: nodeId => void copyReferences([buildReferenceFor(nodeId)]),
      onOpenBoard: openBoardFromCanvas,
      onZoomToSection: sectionId => requestFit([sectionNodeId(sectionId)]),
    }),
    [
      liveBoardIds,
      selectedBoardId,
      selectedBoardIds,
      matchingBoardIds,
      openThreadCounts,
      defaultStatus,
      tool,
      selectBoard,
      copyReferences,
      buildReferenceFor,
      openBoardFromCanvas,
      requestFit,
    ]
  );

  const selectedBoard = selectedBoardId ? (boardsByNodeId.get(selectedBoardId) ?? null) : null;
  const focusedBoard = focusedBoardId ? (boardsByNodeId.get(focusedBoardId) ?? null) : null;
  const threadsOf = (nodeId: string | null): CommentThread[] =>
    nodeId ? buildCommentThreads(commentsByBoardNodeId.get(nodeId) ?? []) : [];
  const boardsWithOpenThreads = layoutOrder.filter(nodeId => openThreadCounts.has(nodeId));
  const codeAtLoadBoard = useMemo(
    () => (codeAtLoad ? findBoardForCode(boards, codeAtLoad) : null),
    [codeAtLoad, boards]
  );
  const initialFitNodeIds = useMemo(() => {
    if (codeAtLoadBoard?.boardKey) return [boardNodeId(codeAtLoadBoard.boardKey)];
    return layout.sections[0] ? [sectionNodeId(layout.sections[0].id)] : [];
  }, [layout, codeAtLoadBoard]);
  const sectionOptions = layout.sections.map(section => ({ value: section.id, label: section.title }));
  const presentedSectionTitle = presentSectionId
    ? (layout.sections.find(section => section.id === presentSectionId)?.title ?? null)
    : null;

  const copySelectedRef = useRef(copySelectedReferences);
  copySelectedRef.current = copySelectedReferences;
  // F toggles fullscreen (the application shell hidden), Esc leaves it, C copies the selected boards' references, R reloads
  // the canvas — not while typing or in a dialog.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target && (target.tagName === 'TEXTAREA' || target.tagName === 'INPUT' || target.isContentEditable)) return;
      if (document.querySelector('[role=dialog]') || event.metaKey || event.ctrlKey || event.altKey) return;
      if (event.key === 'f' || event.key === 'F') setIsFullscreen(value => !value);
      else if (event.key === 'c' || event.key === 'C') copySelectedRef.current();
      else if (event.key === 'r' || event.key === 'R') void reloadCanvasRef.current();
      else if (event.key === 'Escape') setIsFullscreen(false);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);

  const activeFilterCount = (sectionFilter ? 1 : 0) + (statusFilter ? 1 : 0);
  const toolbarStart = (
    <div className={cx(TOOLBAR_GROUP_CLASS, 'min-w-0 pl-3')}>
      <div className="min-w-0 pr-1">
        <h1 className="max-w-72 truncate text-sm leading-tight font-semibold" title={canvasName}>
          {canvasName}
        </h1>
        <p className="text-xs leading-tight text-muted-foreground">
          {boards.length} boards · {layout.sections.length} sections
          {matchingBoardIds !== null ? ` · ${matchingBoardIds.size} matching` : ''}
        </p>
      </div>
      <SearchField
        size="sm"
        value={search}
        onChange={setSearch}
        clearable
        placeholder="Search boards…"
        aria-label="Search boards"
        className="w-44"
      />
      <Popover>
        <PopoverTrigger asChild>
          <button type="button" className={cx(TOOL_BUTTON_CLASS, activeFilterCount > 0 && 'text-brand-emphasis')} aria-label="Filters">
            <ListFilter className="size-4" />
            {activeFilterCount > 0 && (
              <span className="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">
                {activeFilterCount}
              </span>
            )}
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-[22rem] space-y-4 p-4">
          <div className="space-y-1.5">
            <p className="text-[12.5px] font-bold text-muted-foreground">Section</p>
            <Combobox
              size="sm"
              options={[{ value: '', label: 'All sections' }, ...sectionOptions]}
              value={sectionFilter}
              onChange={setSectionFilter}
              placeholder="All sections"
              searchPlaceholder="Search sections…"
              aria-label="Section"
              className="w-full"
            />
          </div>
          <div className="space-y-1.5">
            <p className="text-[12.5px] font-bold text-muted-foreground">Status</p>
            <QuickFilterPills
              options={statusFilterOptions}
              value={statusFilter}
              onChange={setStatusFilter}
              allLabel="All"
              size="sm"
              aria-label="Board status"
            />
          </div>
          {activeFilterCount > 0 && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                setSectionFilter('');
                setStatusFilter('');
              }}
            >
              Clear filters
            </Button>
          )}
        </PopoverContent>
      </Popover>
    </div>
  );

  const toolbarEnd = (
    <>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className={TOOL_BUTTON_CLASS}
            aria-label={texts.reload}
            aria-keyshortcuts="r"
            aria-busy={isFetchingCanvas}
            onClick={() => void reloadCanvas()}
          >
            <RefreshCw className={cx('size-4', isFetchingCanvas && 'animate-spin motion-reduce:animate-none')} />
          </button>
        </TooltipTrigger>
        <TooltipContent side="bottom">{texts.reload}</TooltipContent>
      </Tooltip>
      <Tooltip>
        <TooltipTrigger asChild>
          <span>
            <button
              type="button"
              className={TOOL_BUTTON_CLASS}
              disabled={selectedBoardIds.size === 0}
              aria-label={`Copy reference of ${selectedBoardIds.size} selected board(s) for an agent (C)`}
              onClick={copySelectedReferences}
            >
              <ClipboardCopy className="size-4" />
              {selectedBoardIds.size > 1 && (
                <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] font-bold text-foreground">
                  {selectedBoardIds.size}
                </span>
              )}
            </button>
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          {selectedBoardIds.size === 0 ? 'Select boards to copy their reference' : 'Copy reference for an agent (C)'}
        </TooltipContent>
      </Tooltip>
      {hasThemes && themes.length > 0 && (
        <Combobox
          shape="pill"
          size="sm"
          options={themeOptions}
          value={themeId ?? ''}
          onChange={value => setCanvasStateValue('theme', value === (referenceThemeId ?? '') ? '' : value)}
          isLoading={isLoadingThemes}
          placeholder="Theme"
          searchPlaceholder="Search themes…"
          aria-label="Theme"
          className="w-44"
        />
      )}
      {isRendererEnabled && missingThumbnailCount > 0 && renderStatus !== null && renderStatus !== 'DONE' ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              disabled={renderStatus !== 'FAILED' || !writesAllowed}
              className="flex h-8 shrink-0 items-center gap-1.5 rounded-full bg-tint px-3 text-xs font-semibold text-muted-foreground transition-colors enabled:hover:bg-tint-strong enabled:hover:text-foreground"
              onClick={() => fileRenderRequest(true)}
            >
              {renderStatus === 'FAILED' ? (
                <ImageDown className="size-4" aria-hidden />
              ) : (
                <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden />
              )}
              {renderStatus === 'FAILED'
                ? texts.thumbnailsFailed
                : renderStatus === 'WAITING_FOR_RENDERER'
                  ? texts.thumbnailsWaitingForRenderer(shownThemeLabel)
                  : texts.thumbnailsRendering(shownThemeLabel)}{' '}
              · {Math.max(0, shownBoardList.length - missingThumbnailCount)}/{shownBoardList.length}
            </button>
          </TooltipTrigger>
          <TooltipContent side="bottom" className="max-w-sm">
            {texts.thumbnailsMissing(missingThumbnailCount)}
            {canvas?.renderRequestError ? ` ${texts.thumbnailsLastError(canvas.renderRequestError)}` : ''}
          </TooltipContent>
        </Tooltip>
      ) : null}
      {hasThemes && themes.length > 0 && (
        <Tooltip>
          <TooltipTrigger asChild>
            <span>
              <button
                type="button"
                className={TOOL_BUTTON_CLASS}
                disabled={!supportsDark}
                aria-label={effectiveMode === 'dark' ? 'Light theme' : 'Dark theme'}
                aria-pressed={effectiveMode === 'dark'}
                onClick={() => {
                  const nextMode: ThemeMode = effectiveMode === 'dark' ? 'light' : 'dark';
                  setCanvasStateValue('mode', nextMode === defaultMode ? '' : nextMode);
                }}
              >
                {effectiveMode === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
              </button>
            </span>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            {supportsDark ? (effectiveMode === 'dark' ? 'Light theme' : 'Dark theme') : texts.themeWithoutDarkMode}
          </TooltipContent>
        </Tooltip>
      )}
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className={cx(TOOL_BUTTON_CLASS, isCommentsPanelOpen && 'bg-brand-light text-brand-emphasis')}
            aria-label={isCommentsPanelOpen ? 'Hide comments' : 'Show comments'}
            aria-pressed={isCommentsPanelOpen}
            onClick={toggleCommentsPanel}
          >
            {isCommentsPanelOpen ? <PanelRightClose className="size-4" /> : <PanelRightOpen className="size-4" />}
            {openThreadTotal > 0 && (
              <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground">
                {openThreadTotal}
              </span>
            )}
          </button>
        </TooltipTrigger>
        <TooltipContent side="bottom">{isCommentsPanelOpen ? 'Hide comments' : 'Show comments'}</TooltipContent>
      </Tooltip>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className={cx(TOOL_BUTTON_CLASS, isFullscreen && 'bg-brand-light text-brand-emphasis')}
            aria-label={isFullscreen ? 'Exit fullscreen (F)' : 'Fullscreen (F)'}
            aria-pressed={isFullscreen}
            onClick={() => setIsFullscreen(value => !value)}
          >
            {isFullscreen ? <Minimize className="size-4" /> : <Maximize className="size-4" />}
          </button>
        </TooltipTrigger>
        <TooltipContent side="bottom">{isFullscreen ? 'Exit fullscreen (F)' : 'Fullscreen (F)'}</TooltipContent>
      </Tooltip>
    </>
  );

  return (
    <div
      data-page-frame
      data-canvas-fullscreen={isFullscreen ? '' : undefined}
      className={cx(
        // on the shell's cloud background (transparent); fullscreen covers the shell with the flat sky colour
        'relative flex h-[calc(100dvh_-_var(--nav-h))] min-h-[560px] flex-col lg:flex-row',
        isFullscreen && 'fixed inset-0 z-40 h-dvh bg-background'
      )}
    >
      {error && (
        <div className="p-6">
          <StatusCallout
            tone="destructive"
            title="The canvas could not be loaded"
            description={normalizeDddError(error, 'Request failed').message}
          />
        </div>
      )}
      {(isLoading || (!isLookResolved && !canvas)) && <Skeleton className="m-6 flex-1 rounded-2xl" />}
      {canvas && (
        <CanvasViewContext.Provider value={canvasView}>
          <div ref={canvasAreaRef} className="relative min-h-[480px] min-w-0 flex-1">
            {canvasAspectRatio !== null && (
            <DesignCanvasFlow
              layout={layout}
              boardsByNodeId={boardsByNodeId}
              fitRequest={fitRequest}
              initialFitNodeIds={initialFitNodeIds}
              initialViewport={initialViewport}
              onViewportCommit={commitViewport}
              tool={tool}
              onToolChange={setTool}
              liveMinRatio={
                !isRendererEnabled || missingThumbnailCount > 0
                  ? LIVE_MIN_RENDERED_WIDTH_RATIO_WITHOUT_THUMBNAILS
                  : LIVE_MIN_RENDERED_WIDTH_RATIO
              }
              onLiveBoardIdsChange={setLiveBoardIds}
              onVisibleBoardIdsChange={rememberVisibleBoardIds}
              onPaneClick={clearSelection}
              onMarqueeSelect={selectBoards}
              onPresentSection={presentSection}
              toolbarStart={toolbarStart}
              toolbarEnd={toolbarEnd}
              selectedNodeId={selectedBoardId}
            />
            )}
          </div>
          {isCommentsPanelOpen && (
            <aside className="surface-glass-strong m-3 shrink-0 overflow-y-auto rounded-3xl p-5 ring-1 ring-foreground/5 lg:my-3 lg:ml-0 lg:w-[380px]">
              {selectedBoard ? (
                <SelectedBoardPanel
                  key={selectedBoard.boardKey ?? ''}
                  projectKey={projectKey}
                  canvasKey={canvasKey}
                  board={selectedBoard}
                  statuses={statuses}
                  sectionTitle={sectionTitleByKey.get(selectedBoard.sectionKey ?? '') ?? null}
                  threads={threadsOf(selectedBoardId)}
                  writesAllowed={writesAllowed}
                  onOpen={() => selectedBoardId && openBoardFromCanvas(selectedBoardId)}
                  onCopyLink={selectedBoard.code ? () => void copyBoardLink(selectedBoard) : undefined}
                  onStatusChanged={status => selectedBoardId && setBoardStatus(selectedBoardId, status)}
                  onCopyThreadReference={copyThreadReference}
                />
              ) : (
                <div className="space-y-4">
                  <div>
                    <h2 className="text-title-card font-semibold">Open comments</h2>
                    <p className="text-sm text-muted-foreground">
                      Click a board to see its status and comments, double-click to open it large.
                    </p>
                  </div>
                  {boardsWithOpenThreads.length === 0 && (
                    <p className="text-sm text-muted-foreground">No open comments on this canvas.</p>
                  )}
                  <ul className="space-y-1">
                    {boardsWithOpenThreads.map(nodeId => (
                      <li key={nodeId}>
                        <button
                          type="button"
                          className="flex w-full items-center justify-between gap-2 rounded-[10px] px-3 py-2 text-left text-sm transition-colors hover:bg-tint"
                          onClick={() => {
                            setSelectedBoardId(nodeId);
                            requestFit([nodeId]);
                          }}
                        >
                          <span className="truncate">{boardsByNodeId.get(nodeId)?.title}</span>
                          <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-muted-foreground">
                            <MessageSquare className="size-3.5" aria-hidden /> {openThreadCounts.get(nodeId)}
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </aside>
          )}
        </CanvasViewContext.Provider>
      )}
      <BoardFocusView
        projectKey={projectKey}
        canvasKey={canvasKey}
        board={focusedBoard}
        statuses={statuses}
        sectionTitle={focusedBoard ? (sectionTitleByKey.get(focusedBoard.sectionKey ?? '') ?? null) : null}
        position={{
          index: focusedBoardId ? navigationOrder.indexOf(focusedBoardId) + 1 : 0,
          count: navigationOrder.length,
        }}
        threads={threadsOf(focusedBoardId)}
        writesAllowed={writesAllowed}
        presentLabel={presentedSectionTitle}
        onClose={closeFocus}
        onPrevious={() => stepFocus(-1)}
        onNext={() => stepFocus(1)}
        onStatusChanged={status => focusedBoardId && setBoardStatus(focusedBoardId, status)}
        onCopyReference={({ region, note }) =>
          focusedBoardId && void copyReferences([buildReferenceFor(focusedBoardId, { region, note })])
        }
        onCopyThreadReference={copyThreadReference}
      />
    </div>
  );
};
