'use client';

import { type Node, type NodeProps, useStore } from '@xyflow/react';
import { ClipboardCopy, Download, Maximize2, MessageSquare } from 'lucide-react';
import { memo, useEffect, useRef, useState } from 'react';

import type { DesignStudioBoardView } from '../api/types';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { boardStatusDefinition } from './boardStatus';
import { thumbnailVariantForZoom } from './boardDetail';
import { boardCodeOf } from './boardKey';
import { BOARD_HEADER_HEIGHT, MAX_BOARD_CANVAS_HEIGHT } from './canvasLayout';
import { withoutContentToken } from './canvasLiveUpdate';
import { useCanvasView } from './canvasView';
import { useCheckedContentUrl, useImageRetry, wasContentUrlLoaded } from './useCheckedContentUrl';
import { useDeferredMount } from './useDeferredMount';
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
 * viewport, one of the nearest six) the sandboxed board HTML is mounted on top — `sandbox=""`: no scripts, no same
 * origin, no forms, no navigation. A board without a thumbnail in the shown look (not rendered yet) is a neutral
 * card until it is live. Only nodes in the viewport are mounted (`onlyRenderVisibleElements`).
 */
const BoardNodeComponent = ({ id, data }: NodeProps<BoardFlowNode>) => {
  const { board } = data;
  const { apiBaseUrl, components, statuses } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const { StatusBadge } = components;
  const statusDefinition = board.status ? boardStatusDefinition(statuses, board.status) : undefined;
  const width = board.width ?? 390;
  const height = board.height ?? 844;
  // the canvas shows at most MAX_BOARD_CANVAS_HEIGHT of a board; the focus view shows it whole
  const visibleHeight = Math.min(height, MAX_BOARD_CANVAS_HEIGHT);
  const isCut = height > visibleHeight;
  const thumbnailVariant = useStore(state => thumbnailVariantForZoom(state.transform[2], width));
  const {
    liveBoardIds,
    selectedBoardIds,
    matchingBoardIds,
    openThreadCounts,
    defaultStatus,
    onSelectBoard,
    onCopyReference,
    onOpenBoard,
  } = useCanvasView();
  const isLive = liveBoardIds.has(id);
  const isSelected = selectedBoardIds.has(id);
  const isDimmed = matchingBoardIds !== null && !matchingBoardIds.has(id);
  const openThreads = openThreadCounts.get(id) ?? 0;
  // mass mounts (a zoom across a level) are spread over frames: a plain placeholder until this node's turn
  const isReady = useDeferredMount();
  // no thumbnail in the shown look (yet): nothing to draw until the board is live
  const isThumbnailMissing = !board.thumbnailUrl;
  const latestThumbnailUrl = designContentUrl(
    apiBaseUrl,
    thumbnailVariant === 'small' ? (board.thumbnailSmallUrl ?? board.thumbnailUrl) : board.thumbnailUrl
  );
  const latestHtmlUrl = designContentUrl(apiBaseUrl, board.htmlUrl);
  // Same content → same URL for the page's lifetime (a rotated content token never reloads an image / iframe, a
  // remount gets the URL the browser cache holds); only a new version or another theme changes the src.
  const [loadedImageUrl, setLoadedImageUrl] = useState<string | null>(null);
  const [loadedFrameUrl, setLoadedFrameUrl] = useState<string | null>(null);
  const thumbnailUrl = useStableContentUrl(latestThumbnailUrl, isSameContent(loadedImageUrl, latestThumbnailUrl));
  const htmlUrl = useStableContentUrl(latestHtmlUrl, isSameContent(loadedFrameUrl, latestHtmlUrl));
  // A board already seen live in this look never falls back to the empty card while it has no thumbnail: it remounts
  // its HTML at once (browser cache, bounded LRU) instead of waiting for the live set.
  const isRememberedLive =
    isThumbnailMissing && thumbnailVariant === 'default' && isReady && wasContentUrlLoaded(htmlUrl);
  const showsLiveHtml = isLive || isRememberedLive;
  // the iframe mounts only after its HTML answered 2xx (never an error body over the thumbnail); retried while live
  const liveHtmlUrl = useCheckedContentUrl(showsLiveHtml && isReady ? htmlUrl : undefined);
  const thumbnailRetry = useImageRetry(thumbnailUrl);
  useEffect(() => {
    if (!liveHtmlUrl) setLoadedFrameUrl(null);
  }, [liveHtmlUrl]);
  const title = board.title ?? board.boardKey ?? '';
  // the board's code (file name without extension) under the title, so a screen can be named unambiguously
  const boardCode = boardCodeOf(board.boardKey ?? '');
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
          'relative overflow-hidden rounded-[20px] bg-card shadow-surface-raised ring-1 ring-foreground/10',
          isSelected && 'ring-4 ring-brand'
        )}
        style={{ width, height: visibleHeight }}
      >
        {thumbnailUrl && (
          // biome-ignore lint/performance/noImgElement: canvas thumbnails are API images at canvas scale, next/image cannot optimise them
          <img
            key={thumbnailRetry.imageKey}
            src={thumbnailUrl}
            alt=""
            width={width}
            height={height}
            loading="lazy"
            decoding="async"
            draggable={false}
            onError={thumbnailRetry.onError}
            onLoad={() => setLoadedImageUrl(thumbnailUrl)}
            className={cx(
              'pointer-events-none absolute inset-0 h-full w-full object-cover object-top select-none',
              thumbnailRetry.isFailed && 'invisible'
            )}
          />
        )}
        {isThumbnailMissing && !isCompact && !(showsLiveHtml && liveHtmlUrl) && (
          <span className="pointer-events-none absolute inset-0 flex items-center justify-center px-6 text-center text-[17px] font-semibold text-muted-foreground">
            {texts.thumbnailMissing}
          </span>
        )}
        {showsLiveHtml && liveHtmlUrl && (
          <iframe
            src={liveHtmlUrl}
            onLoad={() => setLoadedFrameUrl(liveHtmlUrl)}
            title={title || 'Board'}
            sandbox=""
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
