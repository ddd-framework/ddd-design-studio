import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  BOARD_HEADER_HEIGHT,
  computeCanvasLayout,
  columnsForSection,
  findSectionAt,
  MAX_COLUMNS,
  type LayoutBoardInput,
  SECTION_PADDING,
  SECTION_TITLE_HEIGHT,
} from './canvasLayout.ts';

const boards = (sectionId: string, count: number, tallIndex = -1): LayoutBoardInput[] =>
  Array.from({ length: count }, (_, index) => ({
    id: `${sectionId}-${index}`,
    sectionId,
    width: 390,
    height: index === tallIndex ? 1400 : 844,
    displayOrder: index,
  }));

describe('computeCanvasLayout', () => {
  const layout = computeCanvasLayout(
    [
      { id: 'a', title: 'A', displayOrder: 1 },
      { id: 'empty', title: 'Empty', displayOrder: 2 },
      { id: 'b', title: 'B', displayOrder: 3 },
    ],
    [...boards('a', 11, 2), ...boards('b', 3)]
  );

  it('skips sections without boards', () => {
    assert.deepEqual(
      layout.sections.map(section => section.id),
      ['a', 'b']
    );
  });

  it('puts the boards in rows below the title, the second row under the tall first row', () => {
    const first = layout.boards.get('a-0');
    const firstRowIds = [...layout.boards].filter(([id, rect]) => id.startsWith('a-') && rect.y === first?.y);
    const nextRow = layout.boards.get(`a-${firstRowIds.length}`);
    assert.equal(first?.y, (layout.sections[0]?.y ?? 0) + SECTION_PADDING + SECTION_TITLE_HEIGHT);
    if (nextRow) {
      assert.equal(nextRow.x, first?.x);
      assert.ok(nextRow.y > (first?.y ?? 0) + BOARD_HEADER_HEIGHT + 1400);
    }
  });

  it('adapts the columns to the board count, at most MAX_COLUMNS', () => {
    assert.equal(columnsForSection(7, 3), 4);
    assert.equal(columnsForSection(3, 8), 3);
    assert.equal(columnsForSection(45, 4), 12);
    assert.equal(columnsForSection(400, 4), MAX_COLUMNS);
  });

  it('packs the sections into a wide canvas', () => {
    const wide = computeCanvasLayout(
      ['p', 'q', 'r', 's', 't', 'u', 'v'].map((id, index) => ({ id, title: id, displayOrder: index })),
      [7, 18, 23, 42, 31, 45, 19].flatMap((count, index) => boards(['p', 'q', 'r', 's', 't', 'u', 'v'][index], count))
    );
    const fitZoom = Math.min(1172 / wide.bounds.width, 856 / wide.bounds.height);
    assert.ok(fitZoom >= 0.08, `fit zoom ${fitZoom}`);
  });

  it('contains every board in its section', () => {
    for (const section of layout.sections) {
      for (const boardId of section.boardIds) {
        const rect = layout.boards.get(boardId);
        assert.ok(rect);
        assert.ok(rect.x >= section.x && rect.x + rect.width <= section.x + section.width);
        assert.ok(rect.y >= section.y && rect.y + rect.height <= section.y + section.height);
      }
    }
  });

  it('finds the section at a point', () => {
    const section = layout.sections[1];
    assert.equal(findSectionAt(layout.sections, section.x + 10, section.y + 10)?.id, 'b');
  });
});
