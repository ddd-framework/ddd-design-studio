import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { DEFAULT_DESIGN_STUDIO_TEXTS, resolveDesignStudioTexts } from './texts.ts';

describe('resolveDesignStudioTexts', () => {
  it('answers the defaults without replacements', () => {
    assert.equal(resolveDesignStudioTexts(undefined), DEFAULT_DESIGN_STUDIO_TEXTS);
  });

  it('replaces only the given entries and ignores undefined ones', () => {
    const texts = resolveDesignStudioTexts({
      reload: 'Again',
      boardsUpdated: count => `${count}!`,
      canvasUpToDate: undefined,
    });
    assert.equal(texts.reload, 'Again');
    assert.equal(texts.boardsUpdated(2), '2!');
    assert.equal(texts.canvasUpToDate, DEFAULT_DESIGN_STUDIO_TEXTS.canvasUpToDate);
    assert.equal(texts.showUpdatedBoard, DEFAULT_DESIGN_STUDIO_TEXTS.showUpdatedBoard);
  });
});

describe('DEFAULT_DESIGN_STUDIO_TEXTS', () => {
  it('counts boards and references', () => {
    assert.equal(DEFAULT_DESIGN_STUDIO_TEXTS.boardsUpdated(1), '1 board updated');
    assert.equal(DEFAULT_DESIGN_STUDIO_TEXTS.boardsUpdated(3), '3 boards updated');
    assert.equal(DEFAULT_DESIGN_STUDIO_TEXTS.referencesCopied(1), 'Reference copied');
    assert.equal(DEFAULT_DESIGN_STUDIO_TEXTS.referencesCopied(3), 'References copied (3)');
  });
});
