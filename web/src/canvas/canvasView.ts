'use client';

import { createContext, useContext } from 'react';

/**
 * Canvas state the board nodes read without being rebuilt: which boards may show live HTML, the selection, the search
 * / filter matches and the actions. The node array itself only changes when the canvas data changes.
 */
export interface CanvasViewState {
  liveBoardIds: ReadonlySet<string>;
  /** The board shown in the side panel (the last one clicked). */
  selectedBoardId: string | null;
  /** Every selected board (shift / cmd click, marquee): "Copy reference" copies one block per board. */
  selectedBoardIds: ReadonlySet<string>;
  /** Boards matching search and filters; null = no filter active (everything matches). */
  matchingBoardIds: ReadonlySet<string> | null;
  /** Open threads per board node id. */
  openThreadCounts: ReadonlyMap<string, number>;
  /** The status a board has without a stored one (the first configured status): shown without a badge. */
  defaultStatus: string | null;
  /** Hand tool (the default): every drag pans, a click on a board still selects it. */
  isHandTool: boolean;
  /** `isAdditive`: shift / cmd / ctrl click toggles the board in the selection instead of replacing it. */
  onSelectBoard: (boardId: string, isAdditive: boolean) => void;
  /** Copies the design reference of one board (header button). */
  onCopyReference: (boardId: string) => void;
  onOpenBoard: (boardId: string) => void;
  /** Overview card click: fit the view to that section. */
  onZoomToSection: (sectionId: string) => void;
}

export const CanvasViewContext = createContext<CanvasViewState | null>(null);

export const useCanvasView = (): CanvasViewState => {
  const canvasView = useContext(CanvasViewContext);
  if (!canvasView) {
    throw new Error('useCanvasView needs a CanvasViewContext provider');
  }
  return canvasView;
};

/** The node id of a board (its `boardKey`, the file name in the canvas folder). */
export const boardNodeId = (boardKey: string): string => `board-${boardKey}`;

/** The node id of a section (its `sectionKey`). */
export const sectionNodeId = (sectionKey: string): string => `section-${sectionKey}`;
