import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildThemeReference, splitThemeReference } from './designTheme.ts';

describe('theme references', () => {
  it('splits only a trailing mode off the theme id', () => {
    assert.deepEqual(splitThemeReference('brand:1'), { themeId: 'brand:1', mode: 'light' });
    assert.deepEqual(splitThemeReference('brand:1:dark'), { themeId: 'brand:1', mode: 'dark' });
    assert.deepEqual(splitThemeReference(' brand:1:light '), { themeId: 'brand:1', mode: 'light' });
    assert.equal(splitThemeReference(null), null);
    assert.equal(splitThemeReference(''), null);
  });

  it('builds the reference the canvas query takes', () => {
    assert.equal(buildThemeReference('brand:1', 'light'), 'brand:1');
    assert.equal(buildThemeReference('brand:1', 'dark'), 'brand:1:dark');
  });
});
