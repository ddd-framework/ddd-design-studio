import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { contentTokenExpiry, isContentUrlExpiring, resolveStableContentUrl } from './stableContentUrl.ts';

const base64Url = (value: string) => Buffer.from(value).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const token = (expiresAt: number, salt = 'a') =>
  `${base64Url('{"alg":"HS256"}')}.${base64Url(JSON.stringify({ accountId: 1, salt, expiresAt }))}.signature`;
const now = Math.floor(Date.now() / 1000);

describe('stableContentUrl', () => {
  it('reads the expiry of the content token', () => {
    assert.equal(contentTokenExpiry(`/c/1/html?token=${token(now + 600)}&mode=dark`), now + 600);
    assert.equal(contentTokenExpiry('/c/1/html?mode=dark'), null);
    assert.equal(contentTokenExpiry('/c/1/html?token=garbage'), 0);
  });

  it('treats a token within two minutes of its expiry as expiring', () => {
    assert.equal(isContentUrlExpiring(`/c/2?token=${token(now + 60)}`), true);
    assert.equal(isContentUrlExpiring(`/c/2?token=${token(now + 1800)}`), false);
    assert.equal(isContentUrlExpiring('/c/2?size=small'), false);
  });

  it('keeps the first URL of a content while its token is good, whatever token later answers carry', () => {
    const first = `/c/3/thumbnail?token=${token(now + 1800, 'first')}&variantKey=k`;
    const rotated = `/c/3/thumbnail?token=${token(now + 3600, 'second')}&variantKey=k`;
    assert.equal(resolveStableContentUrl(first), first);
    assert.equal(resolveStableContentUrl(rotated), first);
  });

  it('takes the new URL when the content changed or the kept token is about to expire', () => {
    const expiring = `/c/4/thumbnail?token=${token(now + 30, 'old')}`;
    const fresh = `/c/4/thumbnail?token=${token(now + 3600, 'new')}`;
    assert.equal(resolveStableContentUrl(expiring), expiring);
    assert.equal(resolveStableContentUrl(fresh), fresh);
    const otherVariant = `/c/4/thumbnail?token=${token(now + 3600, 'new')}&variantKey=other`;
    assert.equal(resolveStableContentUrl(otherVariant), otherVariant);
  });
});
