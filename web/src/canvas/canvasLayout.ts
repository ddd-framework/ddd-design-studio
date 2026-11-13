/**
 * Auto layout of a design canvas: each section a bold title line above a grid of its
 * boards (rows aligned on their top edge, tall boards allowed), every board with a header line (title + actions) above
 * it. All sections share one row count, so a section's width follows its board count (≤ MAX_COLUMNS per row); the
 * sections are shelf-packed left → right in their order, and the row count and shelf width are chosen so a viewport
 * of TARGET_ASPECT_RATIO shows the whole canvas as large as possible ("Fit all" stays readable). Sections without boards are not drawn. The stored x / y of a board
 * (its place in the source canvas.json) only decide its order inside the section. Pure functions, no React.
 */

export interface LayoutSectionInput {
  id: string;
  title: string;
  displayOrder: number;
}

export interface LayoutBoardInput {
  id: string;
  /** Section id, null = no section ("Weitere Boards" at the end). */
  sectionId: string | null;
  width: number;
  height: number;
  /** Order inside the section (publish order). */
  displayOrder: number;
}

export interface LayoutRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface LayoutSection extends LayoutRect {
  id: string;
  title: string;
  boardCount: number;
  /** Board ids in layout order. */
  boardIds: string[];
}

export interface CanvasLayout {
  sections: LayoutSection[];
  /** Board node rect: the header line plus the board (node id = board id). */
  boards: Map<string, LayoutRect>;
  bounds: LayoutRect;
}

/** Most boards in one row of a section (12–14 per row). */
export const MAX_COLUMNS = 14;
/** Horizontal gap between boards. */
export const BOARD_GAP_X = 44;
/** Vertical gap between board rows (below a board, above the next row's header). */
export const BOARD_GAP_Y = 64;
/** Tallest board height drawn on the canvas; longer boards are cut there (the focus view shows the full length). */
export const MAX_BOARD_CANVAS_HEIGHT = 1400;
/** Height of the header line above each board (title + expand / download). */
export const BOARD_HEADER_HEIGHT = 92;
/** Height of a section's title line plus its gap to the first row. */
export const SECTION_TITLE_HEIGHT = 104;
/** Padding of a section's background around its title and boards. */
export const SECTION_PADDING = 48;
/** Gap between two sections' backgrounds (both directions). */
export const SECTION_GAP = 140;
/** Aspect ratio (width / height) of the canvas area the packing optimises "Fit all" for (a laptop canvas area). */
export const TARGET_ASPECT_RATIO = 1.4;
/** Section key of boards without a section. */
export const UNSECTIONED_ID = 'unsectioned';

interface SectionGrid {
  section: LayoutSectionInput;
  boards: LayoutBoardInput[];
  /** Board rects relative to the section's top-left corner. */
  relativeRects: Map<string, LayoutRect>;
  width: number;
  height: number;
}

/** Rows the layout tries for its sections (all sections share one row count, so shelves stay even). */
const ROW_COUNT_CANDIDATES = [1, 2, 3, 4, 5, 6, 7, 8, 10];

/** Fewest columns of a section with enough boards (no single tall column of boards). */
export const MIN_COLUMNS = 4;

/**
 * Columns of a section's grid for a shared row count: ⌈n / rows⌉, at least MIN_COLUMNS (fewer only with fewer
 * boards), at most MAX_COLUMNS (then it gets more rows).
 */
export const columnsForSection = (boardCount: number, rows: number): number =>
  Math.max(
    Math.min(boardCount, MIN_COLUMNS),
    Math.min(boardCount, MAX_COLUMNS, Math.ceil(boardCount / Math.max(1, rows)))
  );

const buildSectionGrid = (section: LayoutSectionInput, boards: LayoutBoardInput[], rows: number): SectionGrid => {
  const columns = columnsForSection(boards.length, rows);
  const relativeRects = new Map<string, LayoutRect>();
  let rowTop = SECTION_PADDING + SECTION_TITLE_HEIGHT;
  let right = 0;
  for (let rowStart = 0; rowStart < boards.length; rowStart += columns) {
    const row = boards.slice(rowStart, rowStart + columns);
    let left = SECTION_PADDING;
    let rowHeight = 0;
    for (const board of row) {
      const height = BOARD_HEADER_HEIGHT + Math.min(board.height, MAX_BOARD_CANVAS_HEIGHT);
      relativeRects.set(board.id, { x: left, y: rowTop, width: board.width, height });
      left += board.width + BOARD_GAP_X;
      rowHeight = Math.max(rowHeight, height);
    }
    right = Math.max(right, left - BOARD_GAP_X);
    rowTop += rowHeight + BOARD_GAP_Y;
  }
  return {
    section,
    boards,
    relativeRects,
    width: right + SECTION_PADDING,
    height: rowTop - BOARD_GAP_Y + SECTION_PADDING,
  };
};

/** Shelf packing in order: sections left → right, a new shelf when the next one passes `maxShelfWidth`. */
const packShelves = (grids: SectionGrid[], maxShelfWidth: number): { positions: LayoutRect[]; bounds: LayoutRect } => {
  const positions: LayoutRect[] = [];
  let shelfTop = 0;
  let shelfHeight = 0;
  let x = 0;
  let maxRight = 0;
  for (const grid of grids) {
    if (x > 0 && x + grid.width > maxShelfWidth) {
      shelfTop += shelfHeight + SECTION_GAP;
      shelfHeight = 0;
      x = 0;
    }
    positions.push({ x, y: shelfTop, width: grid.width, height: grid.height });
    maxRight = Math.max(maxRight, x + grid.width);
    shelfHeight = Math.max(shelfHeight, grid.height);
    x += grid.width + SECTION_GAP;
  }
  return { positions, bounds: { x: 0, y: 0, width: maxRight, height: shelfTop + shelfHeight } };
};

export const computeCanvasLayout = (
  sections: readonly LayoutSectionInput[],
  boards: readonly LayoutBoardInput[],
  targetAspectRatio: number = TARGET_ASPECT_RATIO
): CanvasLayout => {
  const boardsBySectionId = new Map<string, LayoutBoardInput[]>();
  for (const board of boards) {
    const sectionId = board.sectionId ?? UNSECTIONED_ID;
    const sectionBoards = boardsBySectionId.get(sectionId) ?? [];
    sectionBoards.push(board);
    boardsBySectionId.set(sectionId, sectionBoards);
  }
  const orderedSections: LayoutSectionInput[] = [...sections].sort(
    (first, second) => first.displayOrder - second.displayOrder
  );
  if (boardsBySectionId.has(UNSECTIONED_ID)) {
    orderedSections.push({ id: UNSECTIONED_ID, title: 'Weitere Boards', displayOrder: Number.MAX_SAFE_INTEGER });
  }
  const sectionsWithBoards = orderedSections
    .map(section => ({
      section,
      boards: [...(boardsBySectionId.get(section.id) ?? [])].sort(
        (first, second) => first.displayOrder - second.displayOrder
      ),
    }))
    .filter(entry => entry.boards.length > 0);
  if (sectionsWithBoards.length === 0) {
    return { sections: [], boards: new Map(), bounds: { x: 0, y: 0, width: 0, height: 0 } };
  }

  // Every shared row count × shelf width; keep the packing a viewport of the target aspect ratio shows largest.
  let bestGrids: SectionGrid[] = [];
  let best = { positions: [] as LayoutRect[], bounds: { x: 0, y: 0, width: 1, height: 1 } };
  let bestFitScale = 0;
  for (const rows of ROW_COUNT_CANDIDATES) {
    const grids = sectionsWithBoards.map(entry => buildSectionGrid(entry.section, entry.boards, rows));
    const widestSection = Math.max(...grids.map(grid => grid.width));
    const totalWidth = grids.reduce((sum, grid) => sum + grid.width + SECTION_GAP, -SECTION_GAP);
    for (let step = 0; step <= 32; step++) {
      const packed = packShelves(grids, widestSection + ((totalWidth - widestSection) * step) / 32);
      const fitScale = Math.min(targetAspectRatio / packed.bounds.width, 1 / packed.bounds.height);
      if (fitScale > bestFitScale) {
        bestFitScale = fitScale;
        best = packed;
        bestGrids = grids;
      }
    }
  }
  const grids = bestGrids;

  const layoutSections: LayoutSection[] = [];
  const boardRects = new Map<string, LayoutRect>();
  grids.forEach((grid, index) => {
    const position = best.positions[index];
    for (const [boardId, rect] of grid.relativeRects) {
      boardRects.set(boardId, { ...rect, x: position.x + rect.x, y: position.y + rect.y });
    }
    layoutSections.push({
      id: grid.section.id,
      title: grid.section.title,
      ...position,
      boardCount: grid.boards.length,
      boardIds: grid.boards.map(board => board.id),
    });
  });
  return { sections: layoutSections, boards: boardRects, bounds: best.bounds };
};

/** The section that holds a canvas point, else the one whose centre is nearest. */
export const findSectionAt = (
  sections: readonly LayoutSection[],
  x: number,
  y: number
): LayoutSection | undefined => {
  let nearest: LayoutSection | undefined;
  let nearestDistance = Number.POSITIVE_INFINITY;
  for (const section of sections) {
    if (x >= section.x && x <= section.x + section.width && y >= section.y && y <= section.y + section.height) {
      return section;
    }
    const distance = Math.hypot(x - section.x - section.width / 2, y - section.y - section.height / 2);
    if (distance < nearestDistance) {
      nearest = section;
      nearestDistance = distance;
    }
  }
  return nearest;
};

/** Where "Present this section" starts: a section and the board shown first. */
export interface SectionPresentationStart {
  sectionId: string;
  boardId: string;
}

/**
 * Where "Present this section" starts: with a selected board, its section from that board on; without one, the
 * section in view from its first board. Undefined when there is nothing to present.
 */
export const findSectionPresentationStart = (
  sections: readonly LayoutSection[],
  selectedBoardId: string | null,
  sectionInView: LayoutSection | undefined
): SectionPresentationStart | undefined => {
  if (selectedBoardId) {
    const sectionOfSelectedBoard = sections.find(section => section.boardIds.includes(selectedBoardId));
    if (sectionOfSelectedBoard) return { sectionId: sectionOfSelectedBoard.id, boardId: selectedBoardId };
  }
  const firstBoardId = sectionInView?.boardIds[0];
  return sectionInView && firstBoardId ? { sectionId: sectionInView.id, boardId: firstBoardId } : undefined;
};
