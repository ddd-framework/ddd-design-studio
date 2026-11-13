/**
 * Responsive boards: the viewport a board is shown in (a device preset, rotated, or a free width), the size of a
 * responsive board on the canvas (its device frames side by side) and which comments belong to a viewport. Pure
 * functions, no React (tested in deviceViewport.test.ts).
 */

import type { DesignDevice, DesignStudioBoardFrameView, DesignStudioBoardView, DesignStudioComment } from '../api/types';

/** Gap between the device frames of a responsive board on the canvas (the backend's FRAME_GAP). */
export const FRAME_GAP = 48;

/** Height of the label line above each device frame on the canvas. */
export const FRAME_LABEL_HEIGHT = 40;

/** Narrowest and widest free width of the focus view's drag handle (the backend's device width bounds). */
export const MINIMUM_VIEWPORT_WIDTH = 240;
export const MAXIMUM_VIEWPORT_WIDTH = 3840;

/** Highest a responsive board is drawn on the canvas in page mode (longer pages are cut, whole in the focus view). */
export const MAX_PAGE_CANVAS_HEIGHT = 8000;

/** How high the device frames of responsive boards are drawn on the canvas. */
export type FrameHeightMode = 'viewport' | 'page';

/** The viewport a responsive board is shown in. `deviceKey` null = a free width (or a rotation without a preset). */
export interface DeviceViewport {
  width: number;
  height: number;
  deviceKey: string | null;
}

export const isResponsiveBoard = (board: DesignStudioBoardView | null | undefined): boolean =>
  Boolean(board?.isResponsive && (board.frames?.elements?.length ?? 0) > 0);

export const framesOf = (board: DesignStudioBoardView | null | undefined): DesignStudioBoardFrameView[] =>
  board?.frames?.elements ?? [];

export const viewportOfDevice = (device: DesignDevice): DeviceViewport => ({
  width: device.width ?? 0,
  height: device.height ?? 0,
  deviceKey: device.deviceKey ?? null,
});

/**
 * The viewport a responsive board opens in: the current one when there is one (a device picked in the focus view or
 * for present mode stays while stepping through boards), else the board's first frame.
 */
export const initialViewportForBoard = (
  board: DesignStudioBoardView | null | undefined,
  current: DeviceViewport | null
): DeviceViewport | null => {
  if (!isResponsiveBoard(board)) return null;
  if (current) return current;
  const firstFrame = framesOf(board)[0];
  return { width: firstFrame.width ?? 0, height: firstFrame.height ?? 0, deviceKey: firstFrame.deviceKey ?? null };
};

/** Landscape ↔ portrait: width and height swapped; the preset with exactly that size when there is one. */
export const rotateViewport = (viewport: DeviceViewport, devices: readonly DesignDevice[]): DeviceViewport => {
  const rotated = { width: viewport.height, height: viewport.width };
  const matchingDevice = devices.find(device => device.width === rotated.width && device.height === rotated.height);
  return { ...rotated, deviceKey: matchingDevice?.deviceKey ?? null };
};

/** A free width from the drag handle (bounded); the height stays, the preset is left. */
export const viewportWithWidth = (viewport: DeviceViewport, width: number): DeviceViewport => ({
  width: Math.round(Math.max(MINIMUM_VIEWPORT_WIDTH, Math.min(MAXIMUM_VIEWPORT_WIDTH, width))),
  height: viewport.height,
  deviceKey: null,
});

/** `iPhone 16 / 15 · 393 × 852`, `Free width · 900 × 852`. */
export const viewportLabel = (viewport: DeviceViewport, devices: readonly DesignDevice[]): string => {
  const device = viewport.deviceKey ? devices.find(candidate => candidate.deviceKey === viewport.deviceKey) : undefined;
  return `${device?.label ?? (viewport.deviceKey ?? 'Free width')} · ${viewport.width} × ${viewport.height}`;
};

/**
 * The page height of a responsive board at a width: the rendered height of the frame of that width; else the frame of
 * the nearest width (a narrower page is taller: scaled by the width ratio); else three screens.
 */
export const pageHeightAtWidth = (board: DesignStudioBoardView, width: number, viewportHeight: number): number => {
  const frames = framesOf(board).filter(frame => (frame.pageHeight ?? 0) > 0 && (frame.width ?? 0) > 0);
  const exactFrame = frames.find(frame => frame.width === width);
  if (exactFrame) return Math.max(viewportHeight, exactFrame.pageHeight ?? 0);
  if (frames.length === 0) return viewportHeight * 3;
  const nearestFrame = frames.reduce((nearest, frame) =>
    Math.abs((frame.width ?? 0) - width) < Math.abs((nearest.width ?? 0) - width) ? frame : nearest
  );
  return Math.max(viewportHeight, Math.round(((nearestFrame.pageHeight ?? 0) * (nearestFrame.width ?? 1)) / Math.max(1, width)));
};

/** Height of one device frame on the canvas in a mode (page mode: the rendered page, once there is one). */
export const frameCanvasHeight = (frame: DesignStudioBoardFrameView, mode: FrameHeightMode): number => {
  const viewportHeight = frame.height ?? 0;
  if (mode === 'viewport') return viewportHeight;
  return Math.min(MAX_PAGE_CANVAS_HEIGHT, Math.max(viewportHeight, frame.pageHeight ?? viewportHeight));
};

/** Size of a board on the canvas below its header: a responsive board = its frames side by side with their labels. */
export const boardCanvasSize = (
  board: DesignStudioBoardView,
  mode: FrameHeightMode
): { width: number; height: number } => {
  if (!isResponsiveBoard(board)) return { width: board.width ?? 390, height: board.height ?? 844 };
  const frames = framesOf(board);
  const width = frames.reduce((sum, frame) => sum + (frame.width ?? 0), 0) + FRAME_GAP * (frames.length - 1);
  const height = FRAME_LABEL_HEIGHT + Math.max(...frames.map(frame => frameCanvasHeight(frame, mode)));
  return { width, height };
};

/** The viewport a comment's pin was placed in, null for a comment on a fixed board (or without a pin). */
export const viewportOfComment = (comment: DesignStudioComment): DeviceViewport | null =>
  comment.viewportWidth
    ? { width: comment.viewportWidth, height: comment.viewportHeight ?? comment.viewportWidth, deviceKey: comment.deviceKey ?? null }
    : null;

/**
 * Whether a comment's pin is shown in a viewport: a pin placed in a viewport shows at that width only (its position
 * is a position in the page at that width); a pin without a viewport (a fixed board) shows when no viewport is set.
 */
export const isPinInViewport = (comment: DesignStudioComment, viewport: DeviceViewport | null): boolean => {
  const commentViewport = viewportOfComment(comment);
  if (!viewport) return commentViewport === null;
  return commentViewport !== null && commentViewport.width === viewport.width;
};

/** `393x852 iphone-16` / `900x852`: the viewport as a design reference and the console print it. */
export const viewportReferenceText = (viewport: DeviceViewport): string =>
  `${viewport.width}x${viewport.height}${viewport.deviceKey ? ` ${viewport.deviceKey}` : ''}`;
