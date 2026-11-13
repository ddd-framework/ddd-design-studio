import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { findSectionPresentationStart, type LayoutSection } from './canvasLayout.ts';

const section = (id: string, boardIds: string[]): LayoutSection => ({
  id,
  title: id,
  boardCount: boardIds.length,
  boardIds,
  x: 0,
  y: 0,
  width: 100,
  height: 100,
});

const sections = [section('onboarding', ['a1', 'a2', 'a3']), section('checkout', ['b1', 'b2'])];

describe('findSectionPresentationStart', () => {
  it('starts at the selected board, in its own section', () => {
    assert.deepEqual(findSectionPresentationStart(sections, 'a2', sections[1]), { sectionId: 'onboarding', boardId: 'a2' });
  });

  it('without a selection, starts at the first board of the section in view', () => {
    assert.deepEqual(findSectionPresentationStart(sections, null, sections[1]), { sectionId: 'checkout', boardId: 'b1' });
  });

  it('a selection that is no board of a section falls back to the section in view', () => {
    assert.deepEqual(findSectionPresentationStart(sections, 'gone', sections[0]), { sectionId: 'onboarding', boardId: 'a1' });
  });

  it('nothing to present without a section or boards', () => {
    assert.equal(findSectionPresentationStart([], null, undefined), undefined);
    assert.equal(findSectionPresentationStart([section('empty', [])], null, section('empty', [])), undefined);
  });
});
