import assert from 'node:assert/strict';
import { test } from 'node:test';

import { describeRasterSize, formatByteSize, formatPixelShare } from './svgExportFile.ts';

test('sizes are named in KB from 1 KB and in MB from 1 MB', () => {
  assert.equal(formatByteSize(10), '1 KB');
  assert.equal(formatByteSize(223_952), '219 KB');
  assert.equal(formatByteSize(1_258_291), '1.2 MB');
});

test('the share of differing pixels has one decimal', () => {
  assert.equal(formatPixelShare(0.0533), '5.3 %');
  assert.equal(formatPixelShare(-1), '0.0 %');
});

test('the raster size is the WebP when there is one, else the PNG', () => {
  assert.equal(describeRasterSize(30_720, 42_029), 'WebP 30 KB');
  assert.equal(describeRasterSize(null, 42_029), 'PNG 41 KB');
});
