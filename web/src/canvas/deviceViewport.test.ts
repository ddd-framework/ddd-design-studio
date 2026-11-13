import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import type { DesignDevice, DesignStudioBoardView } from '../api/types';
import {
  FRAME_GAP,
  FRAME_LABEL_HEIGHT,
  boardCanvasSize,
  initialViewportForBoard,
  siteViewportForBoard,
  isPinInViewport,
  pageHeightAtWidth,
  rotateViewport,
  viewportLabel,
  viewportReferenceText,
  viewportWithWidth,
} from './deviceViewport.ts';

const devices: DesignDevice[] = [
  { deviceKey: 'ipad-10-landscape', label: 'iPad landscape', width: 1180, height: 820 },
  { deviceKey: 'ipad-10-portrait', label: 'iPad portrait', width: 820, height: 1180 },
  { deviceKey: 'iphone-16', label: 'iPhone 16', width: 393, height: 852 },
];

const responsiveBoard: DesignStudioBoardView = {
  boardKey: 'home.html',
  isResponsive: true,
  frames: {
    elements: [
      { deviceKey: 'desktop-1440', width: 1440, height: 900, pageHeight: 3000 },
      { deviceKey: 'iphone-16', width: 393, height: 852, pageHeight: null },
    ],
  },
};

describe('deviceViewport', () => {
  it('opens a responsive board in its first frame, keeps a chosen viewport, ignores fixed boards', () => {
    assert.deepEqual(initialViewportForBoard(responsiveBoard, null), { width: 1440, height: 900, deviceKey: 'desktop-1440' });
    const chosen = { width: 393, height: 852, deviceKey: 'iphone-16' };
    assert.equal(initialViewportForBoard(responsiveBoard, chosen), chosen);
    assert.equal(initialViewportForBoard({ boardKey: 'fixed.html' }, chosen), null);
  });

  it('opens the site view in the widest frame', () => {
    const phoneFirst: DesignStudioBoardView = {
      isResponsive: true,
      frames: { elements: [{ deviceKey: 'iphone-se', width: 375, height: 667 }, { deviceKey: 'desktop-1440', width: 1440, height: 900 }] },
    };
    assert.deepEqual(siteViewportForBoard(phoneFirst, null), { width: 1440, height: 900, deviceKey: 'desktop-1440' });
  });

  it('rotates to the matching preset or to a free size, and a dragged width leaves the preset', () => {
    assert.deepEqual(rotateViewport({ width: 820, height: 1180, deviceKey: 'ipad-10-portrait' }, devices), {
      width: 1180,
      height: 820,
      deviceKey: 'ipad-10-landscape',
    });
    assert.deepEqual(rotateViewport({ width: 393, height: 852, deviceKey: 'iphone-16' }, devices), {
      width: 852,
      height: 393,
      deviceKey: null,
    });
    assert.deepEqual(viewportWithWidth({ width: 393, height: 852, deviceKey: 'iphone-16' }, 100.4), {
      width: 240,
      height: 852,
      deviceKey: null,
    });
    assert.equal(viewportLabel({ width: 393, height: 852, deviceKey: 'iphone-16' }, devices), 'iPhone 16 · 393 × 852');
    assert.equal(viewportLabel({ width: 900, height: 852, deviceKey: null }, devices), 'Free width · 900 × 852');
  });

  it('sizes a responsive board as its frames side by side, by viewport or by rendered page', () => {
    assert.deepEqual(boardCanvasSize(responsiveBoard, 'viewport'), {
      width: 1440 + 393 + FRAME_GAP,
      height: FRAME_LABEL_HEIGHT + 900,
    });
    assert.deepEqual(boardCanvasSize(responsiveBoard, 'page'), {
      width: 1440 + 393 + FRAME_GAP,
      height: FRAME_LABEL_HEIGHT + 3000,
    });
    assert.deepEqual(boardCanvasSize({ width: 390, height: 844 }, 'page'), { width: 390, height: 844 });
  });

  it('estimates the page height at a width from the rendered frames', () => {
    assert.equal(pageHeightAtWidth(responsiveBoard, 1440, 900), 3000);
    assert.equal(pageHeightAtWidth(responsiveBoard, 720, 900), 6000, 'half the width, twice the height');
    assert.equal(pageHeightAtWidth({ isResponsive: true, frames: { elements: [] } }, 400, 800), 2400);
  });

  it('shows a pin only in the viewport width it was placed in', () => {
    const phonePin = { viewportWidth: 393, viewportHeight: 852, deviceKey: 'iphone-16' } as const;
    assert.equal(isPinInViewport(phonePin as never, { width: 393, height: 700, deviceKey: null }), true);
    assert.equal(isPinInViewport(phonePin as never, { width: 1440, height: 900, deviceKey: 'desktop-1440' }), false);
    assert.equal(isPinInViewport({ x: 1, y: 2 } as never, null), true);
    assert.equal(isPinInViewport(phonePin as never, null), false);
    assert.equal(viewportReferenceText({ width: 393, height: 852, deviceKey: 'iphone-16' }), '393x852 iphone-16');
  });
});
