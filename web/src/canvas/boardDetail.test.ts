import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  type BoardRect,
  MAX_LIVE_BOARDS,
  selectLiveBoardIds,
  thumbnailUrlForVariant,
  thumbnailVariantForZoom,
} from './boardDetail.ts';

const board = (id: string, column: number, row = 0): BoardRect => ({
  id,
  x: column * 470,
  y: row * 1000,
  width: 390,
  height: 844,
});

describe('thumbnailVariantForZoom', () => {
  it('uses the small thumbnail at far zoom and the board-width one when larger', () => {
    assert.equal(thumbnailVariantForZoom(0.1, 390), 'small');
    assert.equal(thumbnailVariantForZoom(0.33, 390), 'small');
    assert.equal(thumbnailVariantForZoom(0.4, 390), 'preview');
    assert.equal(thumbnailVariantForZoom(0.5, 1440), 'preview', 'a desktop page at 720 px on screen: the preview');
    assert.equal(thumbnailVariantForZoom(0.6, 1440), 'default');
    assert.equal(
      thumbnailUrlForVariant({ thumbnailUrl: 'full', thumbnailPreviewUrl: null, thumbnailSmallUrl: null }, 'preview'),
      'full',
      'a thumbnail without a preview URL shows the full picture'
    );
    assert.equal(thumbnailUrlForVariant({ thumbnailUrl: 'full', thumbnailPreviewUrl: 'preview' }, 'small'), 'preview');
  });
});

describe('selectLiveBoardIds', () => {
  const screen = { width: 1400, height: 900 };

  it('mounts nothing below the live zoom', () => {
    assert.equal(selectLiveBoardIds([board('a', 0)], { x: 0, y: 0, zoom: 0.5 }, screen).size, 0);
  });

  it('takes only boards inside the viewport', () => {
    const boards = [board('inside', 0), board('far', 40)];
    assert.deepEqual([...selectLiveBoardIds(boards, { x: 0, y: 0, zoom: 1 }, screen)], ['inside']);
  });

  it('caps the live boards and prefers the ones nearest the centre', () => {
    const boards = Array.from({ length: 12 }, (_, index) => board(`b${index}`, index % 6, Math.floor(index / 6)));
    const liveIds = selectLiveBoardIds(boards, { x: 0, y: 0, zoom: 0.6 }, { width: 2000, height: 1300 });
    assert.equal(liveIds.size, MAX_LIVE_BOARDS);
    assert.ok(!liveIds.has('b0') || liveIds.has('b2'));
  });
});
