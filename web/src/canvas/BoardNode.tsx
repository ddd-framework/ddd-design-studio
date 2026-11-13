'use client';

import { type Node, type NodeProps, useStore } from '@xyflow/react';
import { ClipboardCopy, Download, Maximize2, MessageSquare } from 'lucide-react';
import { memo, useEffect, useRef, useState } from 'react';

import type { DesignStudioBoardView } from '../api/types';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { boardStatusDefinition } from './boardStatus';
import { type BoardThumbnailVariant, thumbnailVariantForZoom } from './boardDetail';
import { boardCodeOf } from './boardKey';
import { BOARD_HEADER_HEIGHT, MAX_BOARD_CANVAS_HEIGHT } from './canvasLayout';
import { withoutContentToken } from './canvasLiveUpdate';
import { useCanvasView } from './canvasView';
import { useCheckedContentUrl, wasContentUrlLoaded } from './useCheckedContentUrl';
import { useDeferredMount } from './useDeferredMount';
import { CanvasThumbnail } from './CanvasThumbnail';
import {
  FRAME_GAP,
  FRAME_LABEL_HEIGHT,
  type FrameHeightMode,
  boardCanvasSize,
  frameCanvasHeight,
  framesOf,
  isResponsiveBoard,
} from './deviceViewport';
import type { DesignDevice, DesignStudioBoardFrameView } from '../api/types';
import { useStableContentUrl } from './stableContentUrl';

export type BoardNodeData = { board: DesignStudioBoardView };
export type BoardFlowNode = Node<BoardNodeData, 'board'>;

/** Whether two delivery URLs show the same content (they differ at most in the content token). */
const isSameContent = (first: string | null | undefined, second: string | null | undefined): boolean =>
  Boolean(first && second) && withoutContentToken(first) === withoutContentToken(second);

/**
 * Absolute URL of a delivery path (`/api/...`; `apiBaseUrl` is `environment.apiBaseUrl`, empty = same origin). The path
 * comes complete from the canvas view: content token, content hash and theme are already in it.
 */
export const designContentUrl = (apiBaseUrl: string, path: string | null | undefined): string | undefined =>
  path ? `${apiBaseUrl}${path}` : undefined;

/** File name of a board download (`welcome-2.png`). */
const downloadName = (board: DesignStudioBoardView, extension: string) =>
  `${boardCodeOf(board.boardKey ?? 'board')}.${extension}`;

/** A press that moved further than this (px) was a pan, not a click. */
const CLICK_MAX_MOVE = 5;

/** Header icon button (canvas px: scales with the zoom like the title). */
const HEADER_ICON_BUTTON_CLASS =
  'nodrag nopan flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-tint-strong hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none';

/**
 * One board on the canvas: a header line (title with ellipsis — the full title on hover —,
 * status, open comments, "expand" = focus view, "download" PNG / HTML) above the board. Far out the board shows the
 * ~120 px thumbnail, closer the board-width thumbnail; when the canvas marks it live (large on screen, inside the
 * viewport, one of the nearest six) the sandboxed board HTML is mounted on top — `sandbox=""`: no scripts (a canvas
 * with board scripts: `allow-scripts`, boardSandbox()), no same origin, no forms, no navigation. A board without a thumbnail in the shown look (not rendered yet) is a neutral
 * card until it is live. Only nodes in the viewport are mounted (`onlyRenderVisibleElements`).
 */
const BoardNodeComponent = ({ id, data }: NodeProps<BoardFlowNode>) => {
  const { board } = data;
  const { apiBaseUrl, components, statuses } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const { StatusBadge } = components;
  const statusDefinition = board.status ? boardStatusDefinition(statuses, board.status) : undefined;
  const {
    liveBoardIds,
    selectedBoardIds,
    matchingBoardIds,
    openThreadCounts,
    defaultStatus,
    frameHeightMode,
    devices,
    onSelectBoard,
    onCopyReference,
    onOpenBoard,
  } = useCanvasView();
  const isResponsive = isResponsiveBoard(board);
  const canvasSize = boardCanvasSize(board, frameHeightMode);
  const width = canvasSize.width;
  const height = canvasSize.height;
  // the canvas shows at most MAX_BOARD_CANVAS_HEIGHT of a fixed board; the focus view shows it whole (the frames of a
  // responsive board have their own limit, MAX_PAGE_CANVAS_HEIGHT)
  const visibleHeight = isResponsive ? height : Math.min(height, MAX_BOARD_CANVAS_HEIGHT);
  const isCut = height > visibleHeight;
  const thumbnailVariant = useStore(state => thumbnailVariantForZoom(state.transform[2], width));
  const isLive = liveBoardIds.has(id);
  const isSelected = selectedBoardIds.has(id);
  const isDimmed = matchingBoardIds !== null && !matchingBoardIds.has(id);
  const openThreads = openThreadCounts.get(id) ?? 0;
  // mass mounts (a zoom across a level) are spread over frames: a plain placeholder until this node's turn
  const isReady = useDeferredMount();
  // no thumbnail in the shown look (yet): nothing to draw until the board is live
  const isThumbnailMissing = !isResponsive && !board.thumbnailUrl;
  const latestHtmlUrl = designContentUrl(apiBaseUrl, board.htmlUrl);
  // Same content → same URL for the page's lifetime (a rotated content token never reloads an image / iframe, a
  // remount gets the URL the browser cache holds); only a new version or another theme changes the src.
  const [loadedFrameUrl, setLoadedFrameUrl] = useState<string | null>(null);
  const htmlUrl = useStableContentUrl(latestHtmlUrl, isSameContent(loadedFrameUrl, latestHtmlUrl));
  // A board already seen live in this look never falls back to the empty card while it has no thumbnail: it remounts
  // its HTML at once (browser cache, bounded LRU) instead of waiting for the live set.
  const isRememberedLive =
    isThumbnailMissing && thumbnailVariant !== 'small' && isReady && wasContentUrlLoaded(htmlUrl);
  const showsLiveHtml = isLive || isRememberedLive;
  // the iframe mounts only after its HTML answered 2xx (never an error body over the thumbnail); retried while live
  const liveHtmlUrl = useCheckedContentUrl(showsLiveHtml && isReady ? htmlUrl : undefined);
  useEffect(() => {
    if (!liveHtmlUrl) setLoadedFrameUrl(null);
  }, [liveHtmlUrl]);
  const title = board.title ?? board.boardKey ?? '';
  // the board's code under the title (`APP ONB-W2`; without one, the file name without extension), so a screen can be
  // named unambiguously and opened by its code
  const boardCode = board.code ?? boardCodeOf(board.boardKey ?? '');
  const [isDownloadOpen, setIsDownloadOpen] = useState(false);
  const pressPointRef = useRef<{ x: number; y: number } | null>(null);
  // far out (small thumbnails) the header is only the title: no buttons, no badges to mount per board
  const isCompact = thumbnailVariant === 'small';

  if (!isReady) {
    return (
      <div className={cx('h-full w-full', isDimmed && 'opacity-15')} style={{ paddingTop: BOARD_HEADER_HEIGHT }}>
        <div className="rounded-[20px] bg-card/70" style={{ width, height: visibleHeight }} />
      </div>
    );
  }

  return (
    <div className={cx('relative h-full w-full', isDimmed && 'opacity-15')}>
      {/* two rows: the full title (up to two lines, never squeezed by the buttons), then the code + the buttons */}
      <div className="flex flex-col justify-end gap-0.5 pb-1" style={{ height: BOARD_HEADER_HEIGHT }}>
        <span className="line-clamp-2 text-[19px] leading-6 font-medium text-muted-foreground" title={title}>
          {title}
        </span>
        <div className="flex items-center gap-1">
        <span className="min-w-0 flex-1 truncate text-[15px] leading-5 font-semibold text-foreground/70 tabular-nums" title={boardCode}>
          {isCompact ? '' : boardCode}
        </span>
        {!isCompact && openThreads > 0 && (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-primary px-2 py-0.5 text-sm font-semibold text-primary-foreground">
            <MessageSquare className="size-3.5" aria-hidden />
            {openThreads}
          </span>
        )}
        {!isCompact && board.status && board.status !== defaultStatus && (
          <StatusBadge
            tone={statusDefinition?.tone ?? 'neutral'}
            label={statusDefinition?.label ?? board.status}
            icon={statusDefinition?.icon}
            title={statusDefinition?.description}
            className="shrink-0"
          />
        )}
        {!isCompact && (
          <button
            type="button"
            className={HEADER_ICON_BUTTON_CLASS}
            aria-label={`Copy reference of ${title} for an agent`}
            title="Copy reference (C)"
            onClick={() => onCopyReference(id)}
          >
            <ClipboardCopy className="size-5" />
          </button>
        )}
        {!isCompact && (
        <button
          type="button"
          className={HEADER_ICON_BUTTON_CLASS}
          aria-label={`Open ${title} large`}
          title="Expand"
          onClick={() => onOpenBoard(id)}
        >
          <Maximize2 className="size-5" />
        </button>
        )}
        {!isCompact && (
        <div className="relative">
          <button
            type="button"
            className={HEADER_ICON_BUTTON_CLASS}
            aria-label={`Download ${title}`}
            aria-expanded={isDownloadOpen}
            title="Download"
            onClick={() => setIsDownloadOpen(isOpen => !isOpen)}
          >
            <Download className="size-5" />
          </button>
          {isDownloadOpen && (
            // a plain menu instead of a Radix one: mounted only while open, nothing per node at rest
            <div
              className="nodrag nopan absolute top-full right-0 z-20 mt-1 flex w-56 flex-col rounded-2xl bg-popover p-1.5 text-[18px] shadow-surface-raised ring-1 ring-foreground/5"
            >
              {board.thumbnailUrl && (
                <a
                  href={designContentUrl(apiBaseUrl, board.thumbnailUrl)}
                  download={downloadName(board, 'png')}
                  className="rounded-[10px] px-3 py-2 hover:bg-tint"
                  onClick={() => setIsDownloadOpen(false)}
                >
                  PNG (thumbnail)
                </a>
              )}
              {board.htmlUrl && (
                <a
                  href={htmlUrl}
                  download={downloadName(board, 'html')}
                  className="rounded-[10px] px-3 py-2 hover:bg-tint"
                  onClick={() => setIsDownloadOpen(false)}
                >
                  HTML
                </a>
              )}
            </div>
          )}
        </div>
        )}
        </div>
      </div>
      <div
        className={cx(
          'relative',
          isResponsive
            ? cx('rounded-[20px]', isSelected && 'ring-4 ring-brand ring-offset-8 ring-offset-transparent')
            : cx('overflow-hidden rounded-[20px] bg-card shadow-surface-raised ring-1 ring-foreground/10', isSelected && 'ring-4 ring-brand')
        )}
        style={{ width, height: visibleHeight }}
      >
        {isResponsive && (
          <ResponsiveBoardFrames
            board={board}
            frameHeightMode={frameHeightMode}
            devices={devices}
            thumbnailVariant={thumbnailVariant}
            showsLiveHtml={showsLiveHtml && isReady}
            htmlUrl={htmlUrl}
            runsScripts={board.runsScripts === true}
            title={title}
          />
        )}
        {!isResponsive && !isThumbnailMissing && (
          <CanvasThumbnail
            urls={board}
            variant={thumbnailVariant}
            title={title}
            imageClassName="absolute inset-0 h-full w-full object-cover object-top"
            isCompact={isCompact}
          />
        )}
        {isThumbnailMissing && !isCompact && !(showsLiveHtml && liveHtmlUrl) && (
          <span className="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-[17px] font-semibold text-muted-foreground">
            {texts.thumbnailMissing}
          </span>
        )}
        {!isResponsive && showsLiveHtml && liveHtmlUrl && (
          <iframe
            src={liveHtmlUrl}
            onLoad={() => setLoadedFrameUrl(liveHtmlUrl)}
            title={title || 'Board'}
            sandbox={boardSandbox(board)}
            referrerPolicy="no-referrer"
            loading="lazy"
            width={width}
            height={height}
            className="pointer-events-none absolute inset-0 border-0 bg-transparent"
          />
        )}
        {isCut && (
          <span className="pointer-events-none absolute inset-x-0 bottom-0 flex h-40 items-end justify-center bg-gradient-to-t from-card to-transparent pb-4 text-[16px] font-semibold text-muted-foreground">
            ↓ {Math.round(height)} px — full length in the focus view
          </span>
        )}
        <button
          type="button"
          className="absolute inset-0 z-10 cursor-pointer rounded-[20px] bg-transparent focus-visible:ring-4 focus-visible:ring-ring focus-visible:outline-none"
          onPointerDown={event => {
            pressPointRef.current = { x: event.clientX, y: event.clientY };
          }}
          onClick={event => {
            // a drag that started on the board panned the canvas: no selection on its release
            const pressPoint = pressPointRef.current;
            if (pressPoint && Math.hypot(event.clientX - pressPoint.x, event.clientY - pressPoint.y) > CLICK_MAX_MOVE) return;
            onSelectBoard(id, event.shiftKey || event.metaKey || event.ctrlKey);
          }}
          onDoubleClick={() => onOpenBoard(id)}
          onKeyDown={event => {
            if (event.key === 'Enter' && event.shiftKey) onOpenBoard(id);
          }}
          aria-label={`${title}: select (double-click or Shift+Enter opens it)`}
        />
      </div>
    </div>
  );
};

export const BoardNode = memo(BoardNodeComponent);

/**
 * The sandbox of a board iframe: no scripts, unless the board's own scripts run (a canvas with board scripts); never
 * `allow-same-origin`, so a board never reaches the studio page, its storage or its token.
 */
export const boardSandbox = (board: DesignStudioBoardView | null | undefined): string =>
  board?.runsScripts === true ? 'allow-scripts' : '';

interface ResponsiveBoardFramesProps {
  board: DesignStudioBoardView;
  frameHeightMode: FrameHeightMode;
  devices: readonly DesignDevice[];
  thumbnailVariant: BoardThumbnailVariant;
  /** The board is live: each frame mounts the board HTML at its width. */
  showsLiveHtml: boolean;
  htmlUrl: string | undefined;
  /** The board's own scripts run in each frame (a canvas with board scripts). */
  runsScripts: boolean;
  title: string;
}

/**
 * The device frames of a responsive board side by side: a label line (device and width) above each, the whole-page
 * thumbnail of that width (cut at one screen in viewport mode), the live HTML at that width when the board is live.
 */
const ResponsiveBoardFrames = ({
  board,
  frameHeightMode,
  devices,
  thumbnailVariant,
  showsLiveHtml,
  htmlUrl,
  runsScripts,
  title,
}: ResponsiveBoardFramesProps) => {
  let left = 0;
  return (
    <>
      {framesOf(board).map(frame => {
        const frameLeft = left;
        left += (frame.width ?? 0) + FRAME_GAP;
        return (
          <ResponsiveBoardFrame
            key={frame.deviceKey ?? frameLeft}
            frame={frame}
            left={frameLeft}
            frameHeightMode={frameHeightMode}
            deviceLabel={devices.find(device => device.deviceKey === frame.deviceKey)?.label ?? frame.deviceKey ?? ''}
            thumbnailVariant={thumbnailVariant}
            showsLiveHtml={showsLiveHtml}
            htmlUrl={htmlUrl}
            runsScripts={runsScripts}
            title={title}
          />
        );
      })}
    </>
  );
};

interface ResponsiveBoardFrameProps {
  frame: DesignStudioBoardFrameView;
  left: number;
  frameHeightMode: FrameHeightMode;
  deviceLabel: string;
  thumbnailVariant: BoardThumbnailVariant;
  showsLiveHtml: boolean;
  htmlUrl: string | undefined;
  runsScripts: boolean;
  title: string;
}

const ResponsiveBoardFrame = ({
  frame,
  left,
  frameHeightMode,
  deviceLabel,
  thumbnailVariant,
  showsLiveHtml,
  htmlUrl,
  runsScripts,
  title,
}: ResponsiveBoardFrameProps) => {
  const texts = useDesignStudioTexts();
  const frameWidth = frame.width ?? 0;
  const frameHeight = frameCanvasHeight(frame, frameHeightMode);
  // each frame picks its own variant: a phone frame is drawn far narrower than the desktop frame beside it
  const frameThumbnailVariant = useStore(state =>
    thumbnailVariant === 'small' ? 'small' : thumbnailVariantForZoom(state.transform[2], frameWidth)
  );
  const liveHtmlUrl = useCheckedContentUrl(showsLiveHtml ? htmlUrl : undefined);
  const isCut = frameHeightMode === 'page' && (frame.pageHeight ?? 0) > frameHeight;
  return (
    <div className="absolute top-0" style={{ left, width: frameWidth }}>
      <div
        className="flex items-end truncate pb-2 text-[17px] font-semibold text-muted-foreground tabular-nums"
        style={{ height: FRAME_LABEL_HEIGHT }}
        title={`${deviceLabel} · ${frameWidth} × ${frame.height ?? 0}`}
      >
        {thumbnailVariant === 'small' ? '' : `${deviceLabel} · ${frameWidth}`}
      </div>
      <div
        className="relative overflow-hidden rounded-[20px] bg-card shadow-surface-raised ring-1 ring-foreground/10"
        style={{ width: frameWidth, height: frameHeight }}
      >
        {frame.thumbnailUrl && (
          <CanvasThumbnail
            urls={frame}
            variant={frameThumbnailVariant}
            title={`${title} · ${deviceLabel}`}
            imageClassName="absolute inset-x-0 top-0 w-full"
            isCompact={thumbnailVariant === 'small'}
          />
        )}
        {!frame.thumbnailUrl && !(showsLiveHtml && liveHtmlUrl) && thumbnailVariant !== 'small' && (
          <span className="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-[17px] font-semibold text-muted-foreground">
            {texts.thumbnailMissing}
          </span>
        )}
        {showsLiveHtml && liveHtmlUrl && (
          <iframe
            src={liveHtmlUrl}
            title={`${title} · ${deviceLabel}`}
            sandbox={runsScripts ? 'allow-scripts' : ''}
            referrerPolicy="no-referrer"
            loading="lazy"
            width={frameWidth}
            height={frameHeight}
            className="pointer-events-none absolute inset-0 border-0 bg-transparent"
          />
        )}
        {isCut && (
          <span className="pointer-events-none absolute inset-x-0 bottom-0 flex h-40 items-end justify-center bg-gradient-to-t from-card to-transparent pb-4 text-[16px] font-semibold text-muted-foreground">
            ↓ {Math.round(frame.pageHeight ?? 0)} px — full length in the focus view
          </span>
        )}
      </div>
    </div>
  );
};
