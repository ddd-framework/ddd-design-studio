'use client';

import {
  ChevronLeft,
  ChevronRight,
  ClipboardCopy,
  MapPin,
  MapPinPlus,
  SquareDashedMousePointer,
  X,
} from 'lucide-react';
import { type MouseEvent, type PointerEvent, useEffect, useRef, useState } from 'react';

import type { DesignDevice, DesignStudioBoardView } from '../api/types';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { BoardComments, type BoardPin, type CommentThread } from './BoardComments';
import { designContentUrl } from './BoardNode';
import { shortContentHash } from './canvasLiveUpdate';
import type { BoardRegion } from './designReference';
import { BoardStatusChips } from './BoardStatusChips';
import {
  type DeviceViewport,
  framesOf,
  isPinInViewport,
  isResponsiveBoard,
  pageHeightAtWidth,
  viewportWithWidth,
} from './deviceViewport';
import { ViewportToolbar, WidthDragHandle } from './ViewportControls';
import { useStableContentUrl } from './stableContentUrl';
import { useCheckedContentUrl } from './useCheckedContentUrl';

/** Visible height of the device screen (iPhone 15 point height); taller boards scroll inside the device. */
const DEVICE_SCREEN_HEIGHT = 844;

export interface BoardFocusViewProps {
  projectKey: string;
  canvasKey: string;
  board: DesignStudioBoardView | null;
  /** The configured status keys in display order. */
  statuses: readonly string[];
  sectionTitle: string | null;
  /** Position of the board in the navigation order (1-based) and the count. */
  position: { index: number; count: number };
  threads: CommentThread[];
  writesAllowed: boolean;
  /** Present mode: label of the section being presented. */
  presentLabel?: string | null;
  /** Device presets of the canvas (responsive boards). */
  devices?: readonly DesignDevice[];
  /** The viewport a responsive board is shown in (kept while stepping through boards); null for a fixed board. */
  viewport?: DeviceViewport | null;
  /** Device picked, rotated or width dragged. */
  onViewportChange?: (viewport: DeviceViewport) => void;
  onClose: () => void;
  onPrevious: () => void;
  onNext: () => void;
  onStatusChanged: (status: string) => void;
  /** "Copy reference" (button / C): this board, with the marked region and the note when set. */
  onCopyReference: (options: { region: BoardRegion | null; note: string }) => void;
  /** "Copy for agent" on a comment thread. */
  onCopyThreadReference: (thread: CommentThread) => void;
}

/**
 * One board large: the live sandboxed HTML at its native width in a device frame, scrolling through its full length
 * (a responsive board: in the chosen viewport — device presets, rotation, a free width by the drag handle, scaled down
 * when wider than the screen; pins are shown in the viewport width they were placed in);
 * prev / next through the canvas order (buttons and ← / →); comment pins on the board (“Add pin”, then a click on the
 * spot) and the board's comment threads and status beside it.
 */
export const BoardFocusView = ({
  projectKey,
  canvasKey,
  board,
  statuses,
  sectionTitle,
  position,
  threads,
  writesAllowed,
  presentLabel,
  devices = [],
  viewport = null,
  onViewportChange,
  onClose,
  onPrevious,
  onNext,
  onStatusChanged,
  onCopyReference,
  onCopyThreadReference,
}: BoardFocusViewProps) => {
  const { apiBaseUrl, components } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const { Button, Dialog, DialogContent, DialogDescription, DialogTitle, Textarea } = components;
  const [isPlacingPin, setIsPlacingPin] = useState(false);
  const [pendingPin, setPendingPin] = useState<BoardPin | null>(null);
  const [highlightedCommentId, setHighlightedCommentId] = useState<number | null>(null);
  const [showResolved, setShowResolved] = useState(false);
  // region reference: a rectangle dragged on the board (board pixels) + a note, both for "Copy reference"
  const [isMarkingRegion, setIsMarkingRegion] = useState(false);
  const [region, setRegion] = useState<BoardRegion | null>(null);
  const [regionDraft, setRegionDraft] = useState<{ startX: number; startY: number; x: number; y: number } | null>(null);
  const [referenceNote, setReferenceNote] = useState('');
  const [isRegionFlashing, setIsRegionFlashing] = useState(false);
  const boardKey = board?.boardKey ?? null;

  // A new board starts without a pending pin.
  useEffect(() => {
    if (boardKey === null) return;
    setPendingPin(null);
    setIsPlacingPin(false);
    setHighlightedCommentId(null);
    setRegion(null);
    setRegionDraft(null);
    setIsMarkingRegion(false);
    setReferenceNote('');
  }, [boardKey]);

  useEffect(() => {
    if (!board) return;
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target && (target.tagName === 'TEXTAREA' || target.tagName === 'INPUT' || target.isContentEditable)) return;
      if (event.key === 'c' || event.key === 'C') {
        if (event.metaKey || event.ctrlKey || event.altKey) return;
        event.preventDefault();
        copyReference();
        return;
      }
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        onPrevious();
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        onNext();
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  });

  const isResponsive = isResponsiveBoard(board) && viewport !== null;
  const [showsDeviceFrame, setShowsDeviceFrame] = useState(true);
  const stageRef = useRef<HTMLDivElement | null>(null);
  const [stageWidth, setStageWidth] = useState(0);
  useEffect(() => {
    const stage = stageRef.current;
    if (!stage || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(() => setStageWidth(stage.clientWidth));
    observer.observe(stage);
    setStageWidth(stage.clientWidth);
    return () => observer.disconnect();
  }, [board]);
  const width = isResponsive ? viewport.width : (board?.width ?? 390);
  const height = isResponsive && board ? pageHeightAtWidth(board, viewport.width, viewport.height) : (board?.height ?? 844);
  const screenHeight = isResponsive ? viewport.height : Math.min(height, DEVICE_SCREEN_HEIGHT);
  const bezelPadding = !isResponsive || showsDeviceFrame ? 24 : 0;
  // a viewport wider than the stage is scaled down; positions stay page pixels (divided by the scale)
  const scale = isResponsive && stageWidth > 0 ? Math.min(1, (stageWidth - 48) / (width + bezelPadding)) : 1;
  const frameOfWidth = isResponsive ? framesOf(board).find(frame => frame.width === width) : undefined;
  // the iframe mounts only after the HTML answered 2xx: an error keeps the thumbnail, retried in the background
  // stable per content: a rotated content token does not reload the open board
  const htmlUrl = useCheckedContentUrl(useStableContentUrl(designContentUrl(apiBaseUrl, board?.htmlUrl), true));
  const thumbnailUrl = useStableContentUrl(
    designContentUrl(apiBaseUrl, isResponsive ? frameOfWidth?.thumbnailUrl : board?.thumbnailUrl),
    true
  );
  const pinnedThreads = threads.filter(
    thread =>
      thread.pinNumber !== null &&
      (showResolved || !thread.root.isResolved) &&
      isPinInViewport(thread.root, isResponsive ? viewport : null)
  );
  const hiddenPinCount = threads.filter(thread => thread.pinNumber !== null && !thread.root.isResolved).length -
    pinnedThreads.filter(thread => !thread.root.isResolved).length;

  const copyReference = () => {
    onCopyReference({ region, note: referenceNote });
    if (region) {
      setIsRegionFlashing(true);
      window.setTimeout(() => setIsRegionFlashing(false), 1200);
    }
  };

  const boardPointOf = (event: PointerEvent<HTMLButtonElement>) => {
    const rect = event.currentTarget.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(width, (event.clientX - rect.left) / scale)),
      y: Math.max(0, Math.min(height, (event.clientY - rect.top) / scale)),
    };
  };
  const startRegion = (event: PointerEvent<HTMLButtonElement>) => {
    event.currentTarget.setPointerCapture(event.pointerId);
    const point = boardPointOf(event);
    setRegionDraft({ startX: point.x, startY: point.y, x: point.x, y: point.y });
  };
  const moveRegion = (event: PointerEvent<HTMLButtonElement>) => {
    if (!regionDraft) return;
    const point = boardPointOf(event);
    setRegionDraft({ ...regionDraft, x: point.x, y: point.y });
  };
  const endRegion = () => {
    if (!regionDraft) return;
    const marked = {
      x: Math.min(regionDraft.startX, regionDraft.x),
      y: Math.min(regionDraft.startY, regionDraft.y),
      width: Math.abs(regionDraft.x - regionDraft.startX),
      height: Math.abs(regionDraft.y - regionDraft.startY),
    };
    setRegionDraft(null);
    if (marked.width >= 4 && marked.height >= 4) {
      setRegion(marked);
      setIsMarkingRegion(false);
    }
  };
  const shownRegion: BoardRegion | null = regionDraft
    ? {
        x: Math.min(regionDraft.startX, regionDraft.x),
        y: Math.min(regionDraft.startY, regionDraft.y),
        width: Math.abs(regionDraft.x - regionDraft.startX),
        height: Math.abs(regionDraft.y - regionDraft.startY),
      }
    : region;

  const placePin = (event: MouseEvent<HTMLButtonElement>) => {
    const rect = event.currentTarget.getBoundingClientRect();
    setPendingPin({
      x: Math.round((event.clientX - rect.left) / scale),
      y: Math.round((event.clientY - rect.top) / scale),
      viewport: isResponsive ? viewport : null,
    });
    setIsPlacingPin(false);
  };

  return (
    <Dialog open={board !== null} onOpenChange={open => !open && onClose()}>
      <DialogContent
        showCloseButton={false}
        className="flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none flex-col gap-0 overflow-hidden rounded-3xl p-0 sm:max-w-none"
      >
        {board && (
          <>
            <header className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
              <div className="min-w-0 flex-1">
                <DialogTitle className="truncate text-lg font-semibold">{board.title}</DialogTitle>
                <DialogDescription className="truncate text-xs">
                  {presentLabel ? 'Present · ' : ''}
                  {sectionTitle ? `${sectionTitle} · ` : ''}
                  {board.boardKey} · {shortContentHash(board.contentHash)} · {position.index} / {position.count}
                </DialogDescription>
              </div>
              <BoardStatusChips
                projectKey={projectKey}
                canvasKey={canvasKey}
                board={board}
                statuses={statuses}
                writesAllowed={writesAllowed}
                onStatusChanged={onStatusChanged}
              />
              <div className="flex items-center gap-1">
                <Button variant="outline" size="icon" aria-label="Previous board (←)" onClick={onPrevious}>
                  <ChevronLeft />
                </Button>
                <Button variant="outline" size="icon" aria-label="Next board (→)" onClick={onNext}>
                  <ChevronRight />
                </Button>
                <Button variant="ghost" size="icon" aria-label="Close focus view" onClick={onClose}>
                  <X />
                </Button>
              </div>
            </header>
            <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
              <div className="flex min-h-0 flex-1 flex-col bg-tint">
                {isResponsive && (
                  <ViewportToolbar
                    devices={devices}
                    viewport={viewport}
                    hiddenPinCount={hiddenPinCount}
                    showsDeviceFrame={showsDeviceFrame}
                    onShowsDeviceFrameChange={setShowsDeviceFrame}
                    onViewportChange={nextViewport => onViewportChange?.(nextViewport)}
                  />
                )}
              <div ref={stageRef} className="flex min-h-0 flex-1 items-start justify-center overflow-auto p-6">
                <div style={{ width: (width + bezelPadding) * scale, height: (screenHeight + bezelPadding) * scale }}>
                <div
                  className={cx(
                    'relative origin-top-left',
                    (!isResponsive || showsDeviceFrame) && 'bg-foreground p-3 shadow-surface-raised',
                    !isResponsive || viewport.width < 600 ? 'rounded-[56px]' : 'rounded-[28px]'
                  )}
                  style={{ transform: scale !== 1 ? `scale(${scale})` : undefined, width: width + bezelPadding }}
                >
                  <div
                    className={cx(
                      'relative overflow-y-auto overflow-x-hidden bg-card',
                      !isResponsive || showsDeviceFrame
                        ? !isResponsive || viewport.width < 600
                          ? 'rounded-[44px]'
                          : 'rounded-[18px]'
                        : 'rounded-md ring-1 ring-foreground/10'
                    )}
                    style={{ width, height: screenHeight }}
                  >
                    <div className="relative" style={{ width, height }}>
                      {thumbnailUrl && (
                        // biome-ignore lint/performance/noImgElement: API thumbnail under the live board while it loads
                        <img
                          src={thumbnailUrl}
                          alt=""
                          width={width}
                          height={height}
                          className="pointer-events-none absolute inset-0 select-none"
                        />
                      )}
                      {htmlUrl && (
                        <iframe
                          key={htmlUrl}
                          src={htmlUrl}
                          title={board.title ?? board.boardKey ?? 'Board'}
                          sandbox=""
                          referrerPolicy="no-referrer"
                          width={width}
                          height={height}
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
                      {isMarkingRegion && (
                        <button
                          type="button"
                          aria-label="Drag a rectangle to mark a region of the board"
                          className="absolute inset-0 z-10 cursor-crosshair touch-none bg-brand/5"
                          onPointerDown={startRegion}
                          onPointerMove={moveRegion}
                          onPointerUp={endRegion}
                        />
                      )}
                      {shownRegion && (
                        <span
                          aria-hidden
                          className={cx(
                            'pointer-events-none absolute z-20 rounded-md border-2 border-dashed border-brand bg-brand/10 transition-shadow motion-reduce:transition-none',
                            isRegionFlashing && 'shadow-[0_0_0_6px_var(--brand)]'
                          )}
                          style={{
                            left: shownRegion.x,
                            top: shownRegion.y,
                            width: shownRegion.width,
                            height: shownRegion.height,
                          }}
                        />
                      )}
                      {pinnedThreads.map(thread => (
                        <button
                          key={String(thread.root.id)}
                          type="button"
                          aria-label={`Comment ${thread.pinNumber}`}
                          onMouseEnter={() => setHighlightedCommentId(Number(thread.root.id))}
                          onClick={() => setHighlightedCommentId(Number(thread.root.id))}
                          className={cx(
                            'absolute z-20 flex size-7 -translate-x-1/2 -translate-y-full items-center justify-center rounded-full rounded-bl-none text-xs font-bold shadow-surface-raised ring-2 ring-card',
                            thread.root.isResolved ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground',
                            highlightedCommentId === Number(thread.root.id) && 'ring-brand scale-110'
                          )}
                          style={{ left: thread.root.x ?? 0, top: thread.root.y ?? 0 }}
                        >
                          {thread.pinNumber}
                        </button>
                      ))}
                      {pendingPin && (
                        <span
                          className="absolute z-20 flex size-7 -translate-x-1/2 -translate-y-full items-center justify-center rounded-full rounded-bl-none bg-brand text-card shadow-surface-raised ring-2 ring-card"
                          style={{ left: pendingPin.x, top: pendingPin.y }}
                          aria-hidden
                        >
                          <MapPin className="size-4" />
                        </span>
                      )}
                    </div>
                  </div>
                  {isResponsive && (
                    <WidthDragHandle
                      scale={scale}
                      width={width}
                      onWidthChange={nextWidth => onViewportChange?.(viewportWithWidth(viewport, nextWidth))}
                    />
                  )}
                </div>
                </div>
              </div>
              </div>
              <aside className="flex min-h-0 w-full flex-col border-t border-border lg:w-[400px] lg:border-t-0 lg:border-l">
                <div className="space-y-2 border-b border-border px-5 py-4">
                  <p className="text-sm font-semibold">Reference for an agent</p>
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      variant={isMarkingRegion ? 'default' : 'outline'}
                      size="sm"
                      aria-pressed={isMarkingRegion}
                      onClick={() => setIsMarkingRegion(marking => !marking)}
                    >
                      <SquareDashedMousePointer /> {isMarkingRegion ? 'Drag on the board…' : 'Mark region'}
                    </Button>
                    {region && (
                      <Button variant="ghost" size="sm" onClick={() => setRegion(null)}>
                        Clear region
                      </Button>
                    )}
                    <Button variant="outline" size="sm" className="ml-auto" onClick={copyReference}>
                      <ClipboardCopy /> Copy reference
                    </Button>
                  </div>
                  {region && (
                    <p className="text-xs text-muted-foreground tabular-nums">
                      Region {Math.round(region.x)},{Math.round(region.y)} · {Math.round(region.width)} ×{' '}
                      {Math.round(region.height)} px
                    </p>
                  )}
                  <Textarea
                    value={referenceNote}
                    onChange={event => setReferenceNote(event.target.value)}
                    placeholder={texts.referenceNotePlaceholder}
                    rows={2}
                    aria-label="Note for the reference"
                  />
                </div>
                <div className="flex items-center gap-2 px-5 pt-4">
                  <Button
                    variant={isPlacingPin ? 'default' : 'outline'}
                    size="sm"
                    disabled={!writesAllowed}
                    aria-pressed={isPlacingPin}
                    onClick={() => setIsPlacingPin(placing => !placing)}
                  >
                    <MapPinPlus /> {isPlacingPin ? 'Click on the board…' : 'Add pin'}
                  </Button>
                  <span className="text-xs text-muted-foreground">
                    {threads.filter(thread => !thread.root.isResolved).length} open
                  </span>
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
                    pinHint="“Add pin” marks a spot on the board"
                    onCopyThreadReference={onCopyThreadReference}
                    onSelectViewport={isResponsive ? onViewportChange : undefined}
                  />
                </div>
              </aside>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
};
