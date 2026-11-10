import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { boardCodeOf } from './boardKey.ts';

describe('boardCodeOf', () => {
  it('strips .html and an optional .dc before it', () => {
    assert.equal(boardCodeOf('welcome.html'), 'welcome');
    assert.equal(boardCodeOf('welcome-2.dc.html'), 'welcome-2');
    assert.equal(boardCodeOf('a.b.html'), 'a.b');
  });

  it('leaves other keys alone', () => {
    assert.equal(boardCodeOf('board'), 'board');
    assert.equal(boardCodeOf('welcome.dc'), 'welcome.dc');
    assert.equal(boardCodeOf('welcome.html.txt'), 'welcome.html.txt');
    assert.equal(boardCodeOf(''), '');
  });
});
