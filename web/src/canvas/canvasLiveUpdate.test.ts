import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  formatViewportParam,
  mergeRefetchedBoards,
  parseViewportParam,
  shortContentHash,
  withoutContentToken,
} from './canvasLiveUpdate.ts';

const board = (key: string, contentHash: string, token = 'a', hasThumbnail = true) => {
  const boardKey = `${key}.dc.html`;
  const base = `/api/admin/designStudio/content/app/onboarding/boards/${boardKey}`;
  return {
    boardKey,
    title: `Board ${key}`,
    status: 'DRAFT',
    contentHash,
    htmlUrl: `${base}/html?token=${token}&contentHash=${contentHash}&theme=brand%3A1`,
    thumbnailUrl: hasThumbnail ? `${base}/thumbnail?token=${token}&contentHash=${contentHash}&theme=brand%3A1` : null,
    thumbnailSmallUrl: hasThumbnail
      ? `${base}/thumbnail?token=${token}&contentHash=${contentHash}&theme=brand%3A1&size=small`
      : null,
  };
};

describe('viewport parameter', () => {
  it('formats compactly and parses back', () => {
    assert.equal(formatViewportParam({ x: -1234.56, y: 560.4, zoom: 0.07623 }), '-1235,560,0.0762');
    assert.equal(formatViewportParam({ x: 0, y: 0, zoom: 1 }), '0,0,1');
    assert.deepEqual(parseViewportParam('-1235,560,0.0762'), { x: -1235, y: 560, zoom: 0.0762 });
  });

  it('rejects malformed values', () => {
    for (const value of [null, '', '1,2', '1,2,3,4', 'a,2,0.5', '1,2,0', '1,2,-1', '1,,0.5', '1,2,50']) {
      assert.equal(parseViewportParam(value), null, String(value));
    }
  });
});

describe('mergeRefetchedBoards', () => {
  it('announces nothing for the first response', () => {
    const result = mergeRefetchedBoards(null, [board('a', 'h1')], true);
    assert.deepEqual(result.changedBoardKeys, []);
    assert.equal(result.boards.length, 1);
  });

  it('keeps the previous array when nothing changed (token rotated, URLs kept)', () => {
    const previous = [board('a', 'h1'), board('b', 'h2')];
    const result = mergeRefetchedBoards(previous, [board('a', 'h1', 'b'), board('b', 'h2', 'b')], true);
    assert.equal(result.isChanged, false);
    assert.equal(result.boards, previous);
    assert.deepEqual(result.changedBoardKeys, []);
  });

  it('takes the new URLs of a rotated token once the old one is stale, without counting a change', () => {
    const previous = [board('a', 'h1')];
    const result = mergeRefetchedBoards(previous, [board('a', 'h1', 'b')], false);
    assert.equal(result.isChanged, true);
    assert.deepEqual(result.changedBoardKeys, []);
    assert.match(result.boards[0].htmlUrl, /token=b/);
  });

  it('marks another content hash and new boards as changed; keeps unchanged objects', () => {
    const previous = [board('a', 'h1'), board('b', 'h2'), board('c', 'h3')];
    const result = mergeRefetchedBoards(
      previous,
      [board('a', 'h1x', 'b'), board('b', 'h2', 'b'), board('c', 'h3', 'b'), board('d', 'h4', 'b')],
      true
    );
    assert.deepEqual(result.changedBoardKeys, ['a.dc.html', 'd.dc.html']);
    assert.equal(result.boards[1], previous[1]);
    assert.equal(result.boards[2], previous[2]);
    assert.match(result.boards[0].htmlUrl, /token=b&contentHash=h1x/);
  });

  it('swaps in a thumbnail that arrived for the same version without counting a change', () => {
    const previous = [board('a', 'h1', 'a', false)];
    const result = mergeRefetchedBoards(previous, [board('a', 'h1', 'b')], true);
    assert.deepEqual(result.changedBoardKeys, []);
    assert.equal(result.isChanged, true);
    assert.match(String(result.boards[0].thumbnailUrl), /thumbnail\?token=b/);
    assert.equal(result.boards[0].htmlUrl, previous[0].htmlUrl);
  });

  it('updates metadata in place but keeps the delivery URLs', () => {
    const previous = [board('a', 'h1')];
    const next = { ...board('a', 'h1', 'b'), status: 'IN_REVIEW' };
    const result = mergeRefetchedBoards(previous, [next], true);
    assert.deepEqual(result.changedBoardKeys, []);
    assert.equal(result.boards[0].status, 'IN_REVIEW');
    assert.equal(result.boards[0].htmlUrl, previous[0].htmlUrl);
  });

  it('strips only the token and shortens the content hash', () => {
    assert.equal(withoutContentToken('/x/thumbnail?token=abc&contentHash=k&size=small'), '/x/thumbnail?contentHash=k&size=small');
    assert.equal(withoutContentToken('/x/html?token=abc'), '/x/html');
    assert.equal(shortContentHash('3f9c2ab81d0e'), '3f9c2ab');
    assert.equal(shortContentHash(null), '');
  });
});
