import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  boardCodeMatches,
  boardCodeUrlValue,
  buildBoardCodeLink,
  codeBelongsToCanvas,
  findBoardForCode,
  normalizeBoardCode,
} from './boardCode.ts';

describe('normalizeBoardCode', () => {
  it('keeps letters and digits in upper case', () => {
    assert.equal(normalizeBoardCode(' app onb-w2 '), 'APPONBW2');
    assert.equal(normalizeBoardCode('APP-ONB-W2'), 'APPONBW2');
  });
});

describe('boardCodeMatches', () => {
  it('matches the full code, the code without the project part and the board part', () => {
    for (const typed of ['APP ONB-W2', 'app-onb-w2', 'APPONBW2', 'ONB-W2', 'onb w2', 'W2', 'w-2']) {
      assert.equal(boardCodeMatches(typed, 'APP ONB-W2'), true, typed);
    }
  });
  it('does not match fragments, other boards or a board without a code', () => {
    for (const typed of ['2', 'NBW2', 'APP ONB-W', 'APP ONB-W22', '']) {
      assert.equal(boardCodeMatches(typed, 'APP ONB-W2'), false, typed);
    }
    assert.equal(boardCodeMatches('W2', null), false);
  });
});

describe('codeBelongsToCanvas', () => {
  it('accepts codes that start with the canvas code, with or without the project part', () => {
    assert.equal(codeBelongsToCanvas('APP ONB-W2', 'APP ONB'), true);
    assert.equal(codeBelongsToCanvas('onb-w2', 'APP ONB'), true);
    assert.equal(codeBelongsToCanvas('W2', 'APP ONB'), false);
    assert.equal(codeBelongsToCanvas('APP ONB', 'APP ONB'), false);
    assert.equal(codeBelongsToCanvas('APP SET-1', 'APP ONB'), false);
    assert.equal(codeBelongsToCanvas('APP ONB-W2', null), false);
  });
});

describe('buildBoardCodeLink', () => {
  it('builds the canvas URL with the code and the look', () => {
    assert.equal(boardCodeUrlValue('APP ONB-W2'), 'APP-ONB-W2');
    assert.equal(
      buildBoardCodeLink('https://example.test/studio/app/canvases/onboarding?board=x&vp=1#h', 'APP ONB-W2', 'brand:1', 'dark'),
      'https://example.test/studio/app/canvases/onboarding?code=APP-ONB-W2&theme=brand:1&mode=dark'
    );
    assert.equal(
      buildBoardCodeLink('https://example.test/studio/app/canvases/onboarding', 'APP ONB-W2', null, 'light'),
      'https://example.test/studio/app/canvases/onboarding?code=APP-ONB-W2'
    );
  });
});

describe('findBoardForCode', () => {
  const boards = [
    { boardKey: 'onb-w-2-welcome.dc.html', code: 'APP ONB-W2' },
    { boardKey: 'onb-w-3-name.dc.html', code: 'APP ONB-W3' },
    { boardKey: 'Main.dc.html', code: null },
  ];
  it('finds a board by code or by file name', () => {
    assert.equal(findBoardForCode(boards, 'app-onb-w3')?.boardKey, 'onb-w-3-name.dc.html');
    assert.equal(findBoardForCode(boards, 'W2')?.boardKey, 'onb-w-2-welcome.dc.html');
    assert.equal(findBoardForCode(boards, 'main')?.boardKey, 'Main.dc.html');
    assert.equal(findBoardForCode(boards, 'W4'), null);
  });
});
