import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { DEFAULT_DESIGN_STUDIO_TEXTS } from '../provider/texts.ts';
import { describeThumbnailRenderState, shouldFileRenderRequestAutomatically } from './thumbnailRenderState.ts';

const canvasWith = (fields: Record<string, unknown>) => ({
  isRendererEnabled: true,
  thumbnailPictureCount: 5,
  missingThumbnailCount: 2,
  renderRequestStatus: null,
  renderRequestError: null,
  renderRequestFailedBoards: null,
  isThemeStyleUnstable: false,
  ...fields,
});

describe('describeThumbnailRenderState', () => {
  it('shows nothing without a renderer or when every picture is there', () => {
    assert.equal(describeThumbnailRenderState(canvasWith({ isRendererEnabled: false })), null);
    assert.equal(describeThumbnailRenderState(canvasWith({ missingThumbnailCount: 0, renderRequestStatus: 'DONE' })), null);
  });

  it('shows progress while a request runs or waits for the renderer', () => {
    const rendering = describeThumbnailRenderState(canvasWith({ renderRequestStatus: 'RUNNING' }));
    assert.equal(rendering?.kind, 'rendering');
    assert.equal(rendering?.isBusy, true);
    assert.equal(rendering?.canRetry, false);
    assert.equal(
      DEFAULT_DESIGN_STUDIO_TEXTS.thumbnailsProgress(rendering!.renderedCount, rendering!.pictureCount, rendering!.failedCount),
      '3 of 5'
    );
    assert.equal(describeThumbnailRenderState(canvasWith({ renderRequestStatus: 'QUEUED' }))?.kind, 'rendering');
    assert.equal(describeThumbnailRenderState(canvasWith({ renderRequestStatus: 'WAITING_FOR_RENDERER' }))?.kind, 'waiting');
  });

  it('shows a failed request with its reason and offers the retry', () => {
    const failed = describeThumbnailRenderState(
      canvasWith({ renderRequestStatus: 'FAILED', renderRequestError: 'the theme changed since the request was filed' })
    );
    assert.equal(failed?.kind, 'failed');
    assert.equal(failed?.reason, 'the theme changed since the request was filed');
    assert.equal(failed?.canRetry, true);
    assert.equal(failed?.isBusy, false);
  });

  it('counts "n of m, k failed" with the error per picture', () => {
    const partlyFailed = describeThumbnailRenderState(
      canvasWith({
        missingThumbnailCount: 1,
        renderRequestStatus: 'DONE',
        renderRequestError: '1 picture(s) failed: b.html: storing the thumbnail failed: timeout',
        renderRequestFailedBoards: {
          elements: [{ renderTargetKey: 'b.html', error: 'storing the thumbnail failed: timeout' }],
        },
      })
    );
    assert.equal(partlyFailed?.kind, 'partlyFailed');
    assert.deepEqual(partlyFailed?.failedBoards, [{ renderTargetKey: 'b.html', error: 'storing the thumbnail failed: timeout' }]);
    assert.equal(
      DEFAULT_DESIGN_STUDIO_TEXTS.thumbnailsProgress(partlyFailed!.renderedCount, partlyFailed!.pictureCount, partlyFailed!.failedCount),
      '4 of 5, 1 failed'
    );
    assert.equal(partlyFailed?.canRetry, true);
  });

  it('names an unstable theme even without a request', () => {
    const themeUnstable = describeThumbnailRenderState(
      canvasWith({ isThemeStyleUnstable: true, renderRequestError: 'the theme returns different styles on each call' })
    );
    assert.equal(themeUnstable?.kind, 'themeUnstable');
    assert.equal(themeUnstable?.reason, 'the theme returns different styles on each call');
    assert.equal(themeUnstable?.canRetry, false);
  });

  it('is never empty while thumbnails are missing', () => {
    for (const status of [null, 'QUEUED', 'RUNNING', 'WAITING_FOR_RENDERER', 'DONE', 'FAILED']) {
      assert.notEqual(describeThumbnailRenderState(canvasWith({ renderRequestStatus: status })), null, String(status));
    }
    assert.equal(describeThumbnailRenderState(canvasWith({ renderRequestStatus: 'DONE' }))?.kind, 'pending');
  });
});

describe('shouldFileRenderRequestAutomatically', () => {
  it('files only when nothing is open and no problem is shown', () => {
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({})), true);
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({ renderRequestStatus: 'DONE' })), true);
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({ renderRequestStatus: 'RUNNING' })), false);
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({ renderRequestStatus: 'FAILED' })), false);
    assert.equal(
      shouldFileRenderRequestAutomatically(canvasWith({ renderRequestStatus: 'DONE', renderRequestError: 'b.html: HTTP 400 invalid' })),
      false
    );
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({ isThemeStyleUnstable: true })), false);
    assert.equal(shouldFileRenderRequestAutomatically(canvasWith({ missingThumbnailCount: 0 })), false);
  });
});
