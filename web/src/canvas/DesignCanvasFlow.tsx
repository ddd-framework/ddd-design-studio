'use client';

import '@xyflow/react/dist/style.css';

import {
  Background,
  BackgroundVariant,
  MiniMap,
  type Node,
  Panel,
  ReactFlow,
  ReactFlowProvider,
  useReactFlow,
  useStore,
  type Viewport,
} from '@xyflow/react';
import { ChevronDown, Hand, MousePointer2, Play } from 'lucide-react';
import { type PointerEvent, type ReactNode, type RefObject, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { DesignStudioBoardView } from '../api/types';
import { useDesignStudio, useDesignStudioComponents } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { type BoardFlowNode, BoardNode } from './BoardNode';
import { type BoardRect, selectLiveBoardIds, selectVisibleBoardIds } from './boardDetail';
import { type CanvasLayout, findSectionAt, type LayoutRect } from './canvasLayout';
import { type CanvasViewport, formatViewportParam, withoutContentToken } from './canvasLiveUpdate';
import { sectionNodeId } from './canvasView';
import { type SectionFlowNode, SectionNode } from './SectionNode';
import { resolveStableContentUrl } from './stableContentUrl';

const NODE_TYPES = { board: BoardNode, section: SectionNode };

const ZOOM_PRESETS = [0.25, 0.5, 1];
const MIN_ZOOM = 0.005;
const MAX_ZOOM = 2.5;
/** Wheel zoom: ~12 % per mouse notch (100 px); trackpad / Magic Mouse pixel deltas zoom proportionally. */
const WHEEL_ZOOM_PER_PIXEL = Math.log(1.12) / 100;
/** Pinch (the browser sends ctrl + wheel with small deltas) zooms faster per pixel. */
const PINCH_ZOOM_PER_PIXEL = 0.012;
/** deltaMode 1 (lines, e.g. Firefox: 3 lines per notch) and 2 (pages) in pixels. */
const WHEEL_LINE_PIXELS = 100 / 3;
const WHEEL_PAGE_PIXELS = 800;
/** One wheel event never zooms by more than this many pixels (fast-spinning wheels, coalesced events). */
const WHEEL_MAX_PIXELS = 300;
/** Per animation frame the zoom closes this share of the gap to its target (log scale): smooth, never springy. */
const ZOOM_EASE = 0.35;
/** The viewport goes into the URL after this rest (a smooth zoom ends a move every frame). */
const VIEWPORT_COMMIT_MS = 300;
/** Fit margins (px): sides / bottom, and the top below the canvas toolbar. */
const FIT_PADDING = 24;
const FIT_TOP = 72;
/** Below this zoom the canvas is drawn at the overview level: one card per section, no boards. */
export const OVERVIEW_ZOOM = 0.06;
/** Rest after a pan / zoom before the live boards are recomputed. */
const LIVE_BOARDS_SETTLE_MS = 300;

/** Cover thumbnails per overview card. */
const OVERVIEW_COVER_COUNT = 4;
/** Minimap width (px); its height follows the canvas' aspect ratio. */
const MINIMAP_WIDTH = 200;

/** Canvas tools (select / hand). */
export type CanvasTool = 'select' | 'hand';

/** A request to fit the view to some nodes; `key` changes for every request. */
export interface CanvasFitRequest {
  nodeIds: string[];
  key: number;
}

export interface DesignCanvasFlowProps {
  layout: CanvasLayout;
  boardsByNodeId: ReadonlyMap<string, DesignStudioBoardView>;
  fitRequest: CanvasFitRequest | null;
  /** Nodes the first view fits (the first section: a whole tall canvas would start unreadably small). */
  initialFitNodeIds: string[];
  /** The first view as saved (`?vp=`): wins over `initialFitNodeIds`. */
  initialViewport?: CanvasViewport | null;
  /** A pan / zoom / fit ended on a new viewport (not called for the first view). */
  onViewportCommit?: (viewport: CanvasViewport) => void;
  tool: CanvasTool;
  onToolChange: (tool: CanvasTool) => void;
  /** Rendered width share from which a board shows live HTML (lower when the thumbnails show another theme). */
  liveMinRatio: number;
  onLiveBoardIdsChange: (liveBoardIds: Set<string>) => void;
  /** The boards in the viewport after a pan / zoom settled, nearest the centre first (node ids). */
  onVisibleBoardIdsChange?: (visibleBoardIds: string[]) => void;
  onPaneClick: () => void;
  /** "Present" for the section in the middle of the view. */
  onPresentSection: (sectionId: string) => void;
  /** Left toolbar content after the tools (canvas name, search, filters). */
  toolbarStart: ReactNode;
  /** Controls of the right toolbar group before the zoom (theme, theme mode, comments, fullscreen). */
  toolbarEnd: ReactNode;
  /** The selected board (Shift+2 fits it). */
  selectedNodeId: string | null;
  /** Marquee with the select tool: the boards inside the rectangle (`isAdditive` with shift / cmd / ctrl). */
  onMarqueeSelect: (nodeIds: string[], isAdditive: boolean) => void;
}

/** A marquee in progress, in container pixels. */
interface Marquee {
  startX: number;
  startY: number;
  x: number;
  y: number;
}

/** A press that moved less than this (px) is a click (selects), a longer one a pan (same as BoardNode). */
const CLICK_MAX_MOVE = 5;

/** Below this drag distance (px) a press on the pane is a click, not a marquee. */
const MARQUEE_MIN_DRAG = 4;

const TOOLBAR_GROUP_CLASS = 'surface-glass-strong flex items-center gap-1 rounded-2xl p-1 ring-1 ring-foreground/5';
const TOOL_BUTTON_CLASS =
  'flex size-8 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-tint hover:text-foreground motion-reduce:transition-none';

const ZoomMenu = ({ onFitAll }: { onFitAll: () => void }) => {
  const { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } =
    useDesignStudioComponents();
  const reactFlow = useReactFlow();
  const zoomPercent = useStore(state => Math.round(state.transform[2] * 100));
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className="flex h-8 min-w-16 items-center justify-center gap-1 rounded-xl px-2 text-sm font-semibold tabular-nums transition-colors hover:bg-tint"
          aria-label={`Zoom ${zoomPercent} %`}
        >
          {zoomPercent}%
          <ChevronDown className="size-3.5 text-muted-foreground" aria-hidden />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {ZOOM_PRESETS.map(zoom => (
          <DropdownMenuItem key={zoom} onSelect={() => void reactFlow.zoomTo(zoom, { duration: 250 })}>
            {Math.round(zoom * 100)} %
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => void reactFlow.zoomIn({ duration: 200 })}>Zoom in</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => void reactFlow.zoomOut({ duration: 200 })}>Zoom out</DropdownMenuItem>
        <DropdownMenuItem onSelect={onFitAll}>Fit all</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

const buildNodes = (
  layout: CanvasLayout,
  boardsByNodeId: ReadonlyMap<string, DesignStudioBoardView>,
  isOverview: boolean,
  apiBaseUrl: string
): Node[] => {
  const sectionNodes: SectionFlowNode[] = layout.sections.map(section => ({
    id: sectionNodeId(section.id),
    type: 'section',
    position: { x: section.x, y: section.y },
    width: section.width,
    height: section.height,
    data: {
      sectionId: section.id,
      title: section.title,
      boardCount: section.boardCount,
      isOverview,
      width: section.width,
      coverUrls: isOverview
        ? section.boardIds
            .slice(0, OVERVIEW_COVER_COUNT)
            .map(boardId => boardsByNodeId.get(boardId)?.thumbnailSmallUrl)
            .filter((url): url is string => Boolean(url))
            .map(url => resolveStableContentUrl(`${apiBaseUrl}${url}`))
        : [],
    },
    draggable: false,
    selectable: false,
    focusable: false,
    // overview cards are buttons (click = zoom to the section); React Flow would set pointer-events: none otherwise
    style: isOverview ? { pointerEvents: 'all' } : undefined,
    zIndex: 0,
  }));
  if (isOverview) return sectionNodes;
  const boardNodes: BoardFlowNode[] = [];
  for (const [nodeId, rect] of layout.boards) {
    const board = boardsByNodeId.get(nodeId);
    if (!board) continue;
    boardNodes.push({
      id: nodeId,
      type: 'board',
      position: { x: rect.x, y: rect.y },
      width: rect.width,
      height: rect.height,
      data: { board },
      draggable: false,
      selectable: false,
      // the node's own buttons select / open it; React Flow would set pointer-events: none on a non-selectable node
      style: { pointerEvents: 'all' },
      zIndex: 1,
    });
  }
  return [...sectionNodes, ...boardNodes];
};

/** Values equal for a node update: same identity, or arrays of URLs showing the same content (token aside). */
const isSameNodeValue = (previous: unknown, next: unknown): boolean => {
  if (Object.is(previous, next)) return true;
  if (Array.isArray(previous) && Array.isArray(next)) {
    return (
      previous.length === next.length &&
      previous.every((value, index) =>
        typeof value === 'string' && typeof next[index] === 'string'
          ? withoutContentToken(value) === withoutContentToken(next[index])
          : Object.is(value, next[index])
      )
    );
  }
  return false;
};

/** Shallow comparison of two flat records with isSameNodeValue (`style` and `position` are compared by content). */
const isSameRecord = (previous: object | undefined, next: object | undefined): boolean => {
  if (previous === next) return true;
  if (!previous || !next) return false;
  const previousEntries = Object.entries(previous);
  const nextRecord = next as Record<string, unknown>;
  return (
    previousEntries.length === Object.keys(next).length &&
    previousEntries.every(([key, value]) => key in nextRecord && isSameNodeValue(value, nextRecord[key]))
  );
};

/** The next node list with every node that equals its shown counterpart replaced by the shown object. */
export const reuseUnchangedNodes = (previousNodes: readonly Node[], nextNodes: Node[]): Node[] => {
  if (previousNodes.length === 0) return nextNodes;
  const previousById = new Map(previousNodes.map(node => [node.id, node]));
  let isAnyChanged = previousNodes.length !== nextNodes.length;
  const nodes = nextNodes.map((next, index) => {
    const previous = previousById.get(next.id);
    const isSame =
      previous !== undefined &&
      previous.type === next.type &&
      previous.width === next.width &&
      previous.height === next.height &&
      previous.zIndex === next.zIndex &&
      isSameRecord(previous.position, next.position) &&
      isSameRecord(previous.style, next.style) &&
      isSameRecord(previous.data, next.data);
    if (!isSame || previousNodes[index] !== previous) isAnyChanged = true;
    return isSame ? previous : next;
  });
  return isAnyChanged ? nodes : (previousNodes as Node[]);
};

/**
 * Wheel zoom towards the cursor for every input: mouse wheel (pixel or line deltas), Magic Mouse and trackpad scroll,
 * pinch (ctrl + wheel; Safari gesture events). The zoom eases to its target over a few frames (calm, no overshoot),
 * so a notch of a mouse wheel and a stream of small trackpad deltas both feel continuous; min / max zoom respected.
 * `nowheel` elements keep their own scrolling.
 */
const useWheelZoom = (containerRef: RefObject<HTMLDivElement | null>) => {
  const reactFlow = useReactFlow();
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const minLogZoom = Math.log(MIN_ZOOM);
    const maxLogZoom = Math.log(MAX_ZOOM);
    let targetLogZoom = 0;
    let anchorX = 0;
    let anchorY = 0;
    let frame: number | null = null;
    let frameCount = 0;
    const step = () => {
      const { x, y, zoom } = reactFlow.getViewport();
      const currentLogZoom = Math.log(zoom);
      const difference = targetLogZoom - currentLogZoom;
      frameCount += 1;
      const isLast = Math.abs(difference) < 0.002 || frameCount > 90;
      const nextZoom = Math.exp(isLast ? targetLogZoom : currentLogZoom + difference * ZOOM_EASE);
      // the canvas point under the cursor stays under the cursor
      void reactFlow.setViewport({
        x: anchorX - ((anchorX - x) / zoom) * nextZoom,
        y: anchorY - ((anchorY - y) / zoom) * nextZoom,
        zoom: nextZoom,
      });
      frame = isLast ? null : requestAnimationFrame(step);
    };
    const zoomBy = (logDelta: number, clientX: number, clientY: number) => {
      const bounds = container.getBoundingClientRect();
      anchorX = clientX - bounds.left;
      anchorY = clientY - bounds.top;
      // a new gesture starts from the current zoom (a pan or fit may have changed it)
      if (frame === null) targetLogZoom = Math.log(reactFlow.getZoom());
      targetLogZoom = Math.min(maxLogZoom, Math.max(minLogZoom, targetLogZoom + logDelta));
      frameCount = 0;
      if (frame === null) frame = requestAnimationFrame(step);
    };
    const onWheel = (event: WheelEvent) => {
      if (event.target instanceof Element && event.target.closest('.nowheel')) return;
      event.preventDefault();
      if (isGesturing && event.ctrlKey) return;
      const unit = event.deltaMode === 1 ? WHEEL_LINE_PIXELS : event.deltaMode === 2 ? WHEEL_PAGE_PIXELS : 1;
      const pixels = Math.max(-WHEEL_MAX_PIXELS, Math.min(WHEEL_MAX_PIXELS, event.deltaY * unit));
      zoomBy(-pixels * (event.ctrlKey ? PINCH_ZOOM_PER_PIXEL : WHEEL_ZOOM_PER_PIXEL), event.clientX, event.clientY);
    };
    // Safari reports a trackpad pinch as gesture events (scale relative to the gesture start)
    let gestureScale = 1;
    let isGesturing = false;
    const onGestureStart = (event: Event) => {
      event.preventDefault();
      gestureScale = 1;
      isGesturing = true;
    };
    const onGestureEnd = () => {
      isGesturing = false;
    };
    const onGestureChange = (event: Event) => {
      event.preventDefault();
      const gesture = event as Event & { scale?: number; clientX?: number; clientY?: number };
      const scale = gesture.scale ?? 1;
      if (scale <= 0) return;
      zoomBy(Math.log(scale / gestureScale), gesture.clientX ?? 0, gesture.clientY ?? 0);
      gestureScale = scale;
    };
    container.addEventListener('wheel', onWheel, { passive: false });
    container.addEventListener('gesturestart', onGestureStart);
    container.addEventListener('gesturechange', onGestureChange);
    container.addEventListener('gestureend', onGestureEnd);
    return () => {
      container.removeEventListener('gestureend', onGestureEnd);
      container.removeEventListener('wheel', onWheel);
      container.removeEventListener('gesturestart', onGestureStart);
      container.removeEventListener('gesturechange', onGestureChange);
      if (frame !== null) cancelAnimationFrame(frame);
    };
  }, [containerRef, reactFlow]);
};

const FlowCanvas = ({
  layout,
  boardsByNodeId,
  fitRequest,
  initialFitNodeIds,
  initialViewport = null,
  onViewportCommit,
  tool,
  onToolChange,
  liveMinRatio,
  onLiveBoardIdsChange,
  onVisibleBoardIdsChange,
  onPaneClick,
  onPresentSection,
  toolbarStart,
  toolbarEnd,
  selectedNodeId,
  onMarqueeSelect,
}: DesignCanvasFlowProps) => {
  const { apiBaseUrl, components, statusColors } = useDesignStudio();
  const { Tooltip, TooltipContent, TooltipTrigger } = components;
  const containerRef = useRef<HTMLDivElement>(null);
  const reactFlow = useReactFlow();
  useWheelZoom(containerRef);
  const isOverview = useStore(state => state.transform[2] < OVERVIEW_ZOOM);
  // Structural update: a rebuilt node equal to the shown one keeps the shown object, so React Flow re-renders only
  // boards / sections whose data really changed (a poll or one changed board never redraws the whole canvas).
  const previousNodesRef = useRef<Node[]>([]);
  const nodes = useMemo(() => {
    const reused = reuseUnchangedNodes(
      previousNodesRef.current,
      buildNodes(layout, boardsByNodeId, isOverview, apiBaseUrl)
    );
    previousNodesRef.current = reused;
    return reused;
  }, [layout, boardsByNodeId, isOverview, apiBaseUrl]);
  // The minimap only helps while part of the canvas is outside the view.
  const isEverythingVisible = useStore(state => {
    const [x, y, zoom] = state.transform;
    return (
      x >= -1 &&
      y >= -1 &&
      x + layout.bounds.width * zoom <= state.width + 1 &&
      y + layout.bounds.height * zoom <= state.height + 1
    );
  });
  const boardRects = useMemo<BoardRect[]>(
    () => [...layout.boards].map(([id, rect]) => ({ id, ...rect })),
    [layout]
  );

  // Live boards are recomputed when a pan / zoom ends, never during it: panning stays thumbnail-only. A trackpad pan
  // ends many times (each wheel burst), so the update waits LIVE_BOARDS_SETTLE_MS of rest: passing over boards does
  // not start loads that are thrown away a moment later.
  const liveBoardsTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const cancelLiveBoardsUpdate = useCallback(() => {
    if (liveBoardsTimerRef.current) clearTimeout(liveBoardsTimerRef.current);
    liveBoardsTimerRef.current = null;
  }, []);
  useEffect(() => cancelLiveBoardsUpdate, [cancelLiveBoardsUpdate]);
  const updateLiveBoards = useCallback(
    (viewport: Viewport) => {
      const container = containerRef.current;
      if (!container) return;
      const screen = { width: container.clientWidth, height: container.clientHeight };
      onLiveBoardIdsChange(selectLiveBoardIds(boardRects, viewport, screen, undefined, liveMinRatio));
      onVisibleBoardIdsChange?.(selectVisibleBoardIds(boardRects, viewport, screen));
    },
    [boardRects, onLiveBoardIdsChange, onVisibleBoardIdsChange, liveMinRatio]
  );

  // Fit: the content top-aligned under the toolbar, centred horizontally, never above 100 %.
  const hasInitialFitRef = useRef(false);
  const fitRect = useCallback(
    (rect: LayoutRect, duration: number, fitWidthOnly = false): Viewport | null => {
      const container = containerRef.current;
      if (!container || container.clientWidth === 0 || rect.width <= 0 || rect.height <= 0) return null;
      const width = container.clientWidth;
      const height = container.clientHeight;
      // a section is fitted by its width (a tall section then scrolls), everything else whole
      const widthZoom = (width - 2 * FIT_PADDING) / rect.width;
      const heightZoom = fitWidthOnly ? widthZoom : (height - FIT_TOP - FIT_PADDING) / rect.height;
      const zoom = Math.max(MIN_ZOOM, Math.min(widthZoom, heightZoom, 1));
      const viewport = { x: (width - rect.width * zoom) / 2 - rect.x * zoom, y: FIT_TOP - rect.y * zoom, zoom };
      void reactFlow.setViewport(viewport, { duration }).then(() => {
        updateLiveBoards(viewport);
      });
      return viewport;
    },
    [reactFlow, updateLiveBoards]
  );
  const rectOfNodes = useCallback(
    (nodeIds: string[]): LayoutRect | null => {
      const sectionRects = new Map(layout.sections.map(section => [sectionNodeId(section.id), section as LayoutRect]));
      const rects = nodeIds
        .map(nodeId => sectionRects.get(nodeId) ?? layout.boards.get(nodeId))
        .filter((rect): rect is LayoutRect => rect !== undefined);
      if (rects.length === 0) return null;
      const left = Math.min(...rects.map(rect => rect.x));
      const top = Math.min(...rects.map(rect => rect.y));
      const right = Math.max(...rects.map(rect => rect.x + rect.width));
      const bottom = Math.max(...rects.map(rect => rect.y + rect.height));
      return { x: left, y: top, width: right - left, height: bottom - top };
    },
    [layout]
  );
  const fitNodes = useCallback(
    (nodeIds: string[], duration = 400): Viewport | null => {
      const rect = rectOfNodes(nodeIds);
      const isSingleSection = nodeIds.length === 1 && nodeIds[0].startsWith(sectionNodeId(''));
      return rect ? fitRect(rect, duration, isSingleSection) : null;
    },
    [rectOfNodes, fitRect]
  );
  const fitAll = useCallback(() => {
    fitRect(layout.bounds, 400);
  }, [fitRect, layout]);

  // Each fit request once (the callbacks change with the live threshold, the request does not).
  const handledFitKeyRef = useRef<number | null>(null);
  useEffect(() => {
    if (!fitRequest || handledFitKeyRef.current === fitRequest.key) return;
    handledFitKeyRef.current = fitRequest.key;
    fitNodes(fitRequest.nodeIds);
  }, [fitRequest, fitNodes]);

  // The last viewport reported through onViewportCommit (the first view counts as reported: a fresh page writes no
  // `vp` until the user moves).
  const committedViewportRef = useRef<string | null>(null);

  // First view once the layout and the container exist: the saved viewport (`?vp=`, a reload keeps the place), else
  // the first section (a whole tall canvas would start unreadably small). Retried from the resize observer while the
  // container has no size yet.
  const isFlowReadyRef = useRef(false);
  const initialViewportRef = useRef(initialViewport);
  const fitInitialView = useCallback(() => {
    if (!isFlowReadyRef.current || hasInitialFitRef.current || layout.sections.length === 0) return;
    const container = containerRef.current;
    if (!container || container.clientWidth === 0) return;
    const savedViewport = initialViewportRef.current;
    if (savedViewport) {
      hasInitialFitRef.current = true;
      committedViewportRef.current = formatViewportParam(savedViewport);
      void reactFlow.setViewport(savedViewport, { duration: 0 }).then(() => updateLiveBoards(savedViewport));
      return;
    }
    const viewport = initialFitNodeIds.length > 0 ? fitNodes(initialFitNodeIds, 0) : fitRect(layout.bounds, 0);
    if (viewport) {
      hasInitialFitRef.current = true;
      committedViewportRef.current = formatViewportParam(viewport);
    }
  }, [layout, initialFitNodeIds, fitNodes, fitRect, reactFlow, updateLiveBoards]);
  // the URL gets the viewport once the view rests (a smooth zoom ends a move every frame)
  const viewportCommitTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(
    () => () => {
      if (viewportCommitTimerRef.current) clearTimeout(viewportCommitTimerRef.current);
    },
    []
  );
  const commitViewport = (viewport: Viewport) => {
    if (!hasInitialFitRef.current) return;
    if (viewportCommitTimerRef.current) clearTimeout(viewportCommitTimerRef.current);
    viewportCommitTimerRef.current = setTimeout(() => {
      viewportCommitTimerRef.current = null;
      const value = formatViewportParam(viewport);
      if (value === committedViewportRef.current) return;
      committedViewportRef.current = value;
      onViewportCommit?.(viewport);
    }, VIEWPORT_COMMIT_MS);
  };
  useEffect(() => {
    fitInitialView();
  }, [fitInitialView]);

  // A different live threshold (thumbnails missing / complete) re-evaluates the live boards of the current view.
  useEffect(() => {
    if (hasInitialFitRef.current) updateLiveBoards(reactFlow.getViewport());
  }, [reactFlow, updateLiveBoards]);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const resizeObserver = new ResizeObserver(() => {
      if (hasInitialFitRef.current) updateLiveBoards(reactFlow.getViewport());
      else fitInitialView();
    });
    resizeObserver.observe(container);
    return () => resizeObserver.disconnect();
  }, [reactFlow, updateLiveBoards, fitInitialView]);

  // Keys as in common design tools: V select, H hand, + / − zoom, 0 = 100 %, Shift+1 fit all (not while typing).
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target && (target.tagName === 'TEXTAREA' || target.tagName === 'INPUT' || target.isContentEditable)) return;
      if (document.querySelector('[role=dialog]')) return;
      if (event.metaKey || event.ctrlKey || event.altKey) return;
      if (event.key === 'v' || event.key === 'V') onToolChange('select');
      else if (event.key === 'h' || event.key === 'H' || event.key === 'Escape') onToolChange('hand');
      else if (event.shiftKey && event.code === 'Digit1') fitAll();
      else if (event.shiftKey && event.code === 'Digit2') fitSelectionOrSection();
      else if (event.key === '+' || event.key === '=') void reactFlow.zoomIn({ duration: 200 });
      else if (event.key === '-' || event.key === '_') void reactFlow.zoomOut({ duration: 200 });
      else if (event.key === '0' && !event.shiftKey) void reactFlow.zoomTo(1, { duration: 250 });
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  });

  const sectionInView = () => {
    const container = containerRef.current;
    if (!container || layout.sections.length === 0) return undefined;
    const viewport = reactFlow.getViewport();
    const centreX = (-viewport.x + container.clientWidth / 2) / viewport.zoom;
    const centreY = (-viewport.y + container.clientHeight / 2) / viewport.zoom;
    return findSectionAt(layout.sections, centreX, centreY) ?? layout.sections[0];
  };
  const fitSelectionOrSection = () => {
    if (selectedNodeId && layout.boards.has(selectedNodeId)) {
      fitNodes([selectedNodeId]);
      return;
    }
    const section = sectionInView();
    if (section) fitNodes([sectionNodeId(section.id)]);
  };
  const presentCurrentSection = () => {
    const section = sectionInView();
    if (section) onPresentSection(section.id);
  };
  // Marquee selection with the select tool (Space or the middle button pans meanwhile, React Flow's defaults).
  const [marquee, setMarquee] = useState<Marquee | null>(null);
  const isMarqueeJustEndedRef = useRef(false);
  const startMarquee = (event: PointerEvent<HTMLDivElement>) => {
    const target = event.target as HTMLElement;
    if (tool !== 'select' || event.button !== 0 || !target.classList.contains('react-flow__pane')) return;
    const container = containerRef.current;
    if (!container) return;
    const bounds = container.getBoundingClientRect();
    const startX = event.clientX - bounds.left;
    const startY = event.clientY - bounds.top;
    const isAdditive = event.shiftKey || event.metaKey || event.ctrlKey;
    let current: Marquee = { startX, startY, x: startX, y: startY };
    setMarquee(current);
    const onMove = (moveEvent: globalThis.PointerEvent) => {
      current = { startX, startY, x: moveEvent.clientX - bounds.left, y: moveEvent.clientY - bounds.top };
      setMarquee(current);
    };
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      setMarquee(null);
      if (Math.abs(current.x - startX) < MARQUEE_MIN_DRAG && Math.abs(current.y - startY) < MARQUEE_MIN_DRAG) return;
      isMarqueeJustEndedRef.current = true;
      const topLeft = reactFlow.screenToFlowPosition({
        x: bounds.left + Math.min(startX, current.x),
        y: bounds.top + Math.min(startY, current.y),
      });
      const bottomRight = reactFlow.screenToFlowPosition({
        x: bounds.left + Math.max(startX, current.x),
        y: bounds.top + Math.max(startY, current.y),
      });
      const nodeIds = [...layout.boards]
        .filter(
          ([, rect]) =>
            rect.x < bottomRight.x &&
            rect.x + rect.width > topLeft.x &&
            rect.y < bottomRight.y &&
            rect.y + rect.height > topLeft.y
        )
        .map(([nodeId]) => nodeId);
      onMarqueeSelect(nodeIds, isAdditive);
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  };
  const onPaneClickUnlessMarquee = () => {
    if (isMarqueeJustEndedRef.current) {
      isMarqueeJustEndedRef.current = false;
      return;
    }
    onPaneClick();
  };

  const minimapHeight = Math.round(
    Math.min(180, Math.max(60, (MINIMAP_WIDTH * layout.bounds.height) / Math.max(1, layout.bounds.width)))
  );

  return (
    <div
      ref={containerRef}
      className={cx('relative h-full w-full', tool === 'hand' && 'cursor-grab active:cursor-grabbing')}
      onPointerDownCapture={startMarquee}
    >
      {marquee && (
        <div
          aria-hidden
          className="pointer-events-none absolute z-10 rounded-sm border border-brand bg-brand/10"
          style={{
            left: Math.min(marquee.startX, marquee.x),
            top: Math.min(marquee.startY, marquee.y),
            width: Math.abs(marquee.x - marquee.startX),
            height: Math.abs(marquee.y - marquee.startY),
          }}
        />
      )}
      <ReactFlow
        nodes={nodes}
        edges={[]}
        nodeTypes={NODE_TYPES}
        onlyRenderVisibleElements
        nodesDraggable={false}
        nodesConnectable={false}
        elementsSelectable={false}
        zoomOnDoubleClick={false}
        // every wheel (mouse, Magic Mouse, trackpad scroll and pinch) zooms towards the cursor: our own handler
        // (useWheelZoom), React Flow handles no wheel at all; drag pans (also over boards, they are not draggable)
        panOnScroll={false}
        zoomOnScroll={false}
        zoomOnPinch={false}
        minZoom={MIN_ZOOM}
        maxZoom={MAX_ZOOM}
        defaultViewport={{ x: 0, y: 0, zoom: 0.1 }}
        onInit={() => {
          isFlowReadyRef.current = true;
          fitInitialView();
        }}
        onMoveStart={cancelLiveBoardsUpdate}
        onMoveEnd={(_event, viewport) => {
          cancelLiveBoardsUpdate();
          liveBoardsTimerRef.current = setTimeout(() => updateLiveBoards(viewport), LIVE_BOARDS_SETTLE_MS);
          commitViewport(viewport);
        }}
        onPaneClick={onPaneClickUnlessMarquee}
        panOnDrag={tool === 'hand' ? true : [1, 2]}
        // a press that moved less than this is still a click (d3-zoom otherwise swallows the click after any move)
        paneClickDistance={CLICK_MAX_MOVE}
        selectionOnDrag={false}
        proOptions={{ hideAttribution: true }}
      >
        <Background variant={BackgroundVariant.Dots} gap={32} size={1.5} color="var(--tint-strong)" />
        <Panel position="top-left" className="flex max-w-[calc(100%-32rem)] flex-wrap items-center gap-2">
          <div className={TOOLBAR_GROUP_CLASS} role="toolbar" aria-label="Canvas tools">
            {(
              [
                ['hand', Hand, 'Pan (H / Esc) — drag moves the canvas, click selects a board'],
                ['select', MousePointer2, 'Select area (V) — drag a rectangle to select boards, Space + drag pans'],
              ] as const
            ).map(([toolName, Icon, label]) => (
              <Tooltip key={toolName}>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    aria-label={label}
                    aria-pressed={tool === toolName}
                    onClick={() => onToolChange(toolName)}
                    className={cx(TOOL_BUTTON_CLASS, tool === toolName && 'bg-brand-light text-brand-emphasis')}
                  >
                    <Icon className="size-4" />
                  </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">{label}</TooltipContent>
              </Tooltip>
            ))}
          </div>
          {toolbarStart}
        </Panel>
        <Panel position="top-right">
          <div className={TOOLBAR_GROUP_CLASS}>
            {toolbarEnd}
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  aria-label="Present the section in view"
                  onClick={presentCurrentSection}
                  className={TOOL_BUTTON_CLASS}
                >
                  <Play className="size-4" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="bottom">Present this section (← / →)</TooltipContent>
            </Tooltip>
            <ZoomMenu onFitAll={fitAll} />
          </div>
        </Panel>
        <Panel position="bottom-left" className="pointer-events-none">
          <p className="surface-glass-strong rounded-xl px-3 py-1.5 text-xs text-muted-foreground ring-1 ring-foreground/5">
            Drag to pan · Scroll or pinch to zoom · Click selects · Double-click opens · + / − / 0 · Shift+1 fits all
          </p>
        </Panel>
        {!isEverythingVisible && (
          <MiniMap
            pannable
            zoomable
            ariaLabel="Canvas overview: click or drag to move"
            style={{ width: MINIMAP_WIDTH, height: minimapHeight }}
            nodeColor={node =>
              node.type === 'section'
                ? 'var(--brand-light)'
                : (statusColors?.[(node as BoardFlowNode).data.board.status ?? ''] ?? 'var(--muted-foreground)')
            }
            nodeStrokeWidth={0}
            nodeBorderRadius={24}
            maskColor="rgb(8 9 72 / 0.12)"
            maskStrokeColor="var(--brand)"
            maskStrokeWidth={3}
            onClick={(_event, position) =>
              void reactFlow.setCenter(position.x, position.y, { zoom: reactFlow.getZoom(), duration: 300 })
            }
            className="surface-glass-strong !rounded-xl"
          />
        )}
      </ReactFlow>
    </div>
  );
};

/** The infinite canvas (React Flow / xyflow, MIT): auto-laid-out sections and boards, tools, zoom, minimap. */
export const DesignCanvasFlow = (props: DesignCanvasFlowProps) => (
  <ReactFlowProvider>
    <FlowCanvas {...props} />
  </ReactFlowProvider>
);
