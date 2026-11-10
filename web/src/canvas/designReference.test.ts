import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildBoardDeepLink, buildDesignReference } from './designReference.ts';

const base = {
  projectName: 'App',
  canvasName: 'Onboarding – first run',
  projectKey: 'app',
  canvasKey: 'onboarding',
  boardTitle: '2 Welcome',
  boardKey: 'welcome-2.dc.html',
  contentHash: '3f9c2ab81d0e4c5f6a7b8c9d0e1f2a3b4c5d6e7f',
  themeReference: 'brand:1:dark',
  url: 'https://x.test/studio/app/canvases/onboarding?board=welcome-2.dc.html&theme=brand:1&mode=dark',
};

describe('buildDesignReference', () => {
  it('writes the agreed one-line format', () => {
    assert.equal(
      buildDesignReference(base),
      '[design-ref] App › Onboarding – first run › 2 Welcome · file welcome-2.dc.html · canvas app/onboarding · v3f9c2ab · theme brand:1:dark · https://x.test/studio/app/canvases/onboarding?board=welcome-2.dc.html&theme=brand:1&mode=dark'
    );
  });

  it('names a board without a theme and without a known version', () => {
    const reference = buildDesignReference({ ...base, contentHash: null, themeReference: null });
    assert.ok(reference.includes(' · v? · theme none · https://'));
  });

  it('adds region before the link and the note as an extra line', () => {
    const reference = buildDesignReference({ ...base, region: { x: 24.4, y: 180, width: 342, height: 220 }, note: ' tab bar\n more compact ' });
    const [first, second] = reference.split('\n');
    assert.ok(first.includes(' · v3f9c2ab · theme brand:1:dark · region 24,180,342,220 · https://'));
    assert.equal(second, 'note: tab bar more compact');
  });

  it('adds a comment with its pin and text lines', () => {
    const reference = buildDesignReference({ ...base, comment: { id: 7, x: 195, y: 780, lines: ['Check the tab bar', 'Agent: seen'] } });
    const lines = reference.split('\n');
    assert.ok(lines[0].includes(' · comment 7 at 195,780 · https://'));
    assert.deepEqual(lines.slice(1), ['comment: Check the tab bar', 'comment: Agent: seen']);
  });
});

describe('buildBoardDeepLink', () => {
  it('keeps the path and sets only board, theme and mode', () => {
    assert.equal(
      buildBoardDeepLink('https://x.test/studio/app/canvases/onboarding?q=Team&theme=brand:5', 'welcome-2.dc.html', 'brand:1', 'light'),
      'https://x.test/studio/app/canvases/onboarding?board=welcome-2.dc.html&theme=brand:1&mode=light'
    );
  });

  it('leaves the look out for a board shown as authored', () => {
    assert.equal(
      buildBoardDeepLink('https://x.test/c/onboarding?mode=dark', 'a.dc.html', null, 'light'),
      'https://x.test/c/onboarding?board=a.dc.html'
    );
  });

  it('adds the canvas viewport when given', () => {
    assert.equal(
      buildBoardDeepLink('https://x.test/c/onboarding?vp=1,2,0.5', 'a.dc.html', 'brand:1', 'dark', '-40,80,0.62'),
      'https://x.test/c/onboarding?board=a.dc.html&theme=brand:1&mode=dark&vp=-40,80,0.62'
    );
  });
});
