import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import type { DesignStudioBoardView } from '../api/types';
import { normalizeSitePath, parseSiteFrameMessage, resolveSiteHref, sitePagesOf, startRouteOf } from './siteNavigation.ts';

const boards: DesignStudioBoardView[] = [
  { boardKey: 'home.html', route: '/' },
  { boardKey: 'pricing.html', route: '/pricing' },
  { boardKey: 'faq.html', route: '/help/faq' },
  { boardKey: 'draft.html', route: null },
];

describe('siteNavigation', () => {
  it('normalizes paths', () => {
    assert.equal(normalizeSitePath(''), '/');
    assert.equal(normalizeSitePath('pricing/'), '/pricing');
    assert.equal(normalizeSitePath('/a/./b'), '/a/b');
    assert.equal(normalizeSitePath('/a?x'), null);
    assert.equal(normalizeSitePath('/../x'), null);
  });

  it('opens at the given page, else the start page, else the first page', () => {
    const pages = sitePagesOf(boards);
    assert.equal(startRouteOf(pages, '/pricing/'), '/pricing');
    assert.equal(startRouteOf(pages, '/nope'), '/');
    assert.equal(startRouteOf(sitePagesOf([{ boardKey: 'x', route: '/x' }]), null), '/x');
    assert.equal(startRouteOf(new Map(), null), null);
  });

  it('resolves links to pages, anchors, external links and missing pages', () => {
    assert.deepEqual(resolveSiteHref('/pricing#plans', '/', boards), {
      kind: 'page',
      board: boards[1],
      route: '/pricing',
      hash: '#plans',
    });
    assert.equal(resolveSiteHref('faq', '/help/start', boards).kind, 'page', 'relative to the current folder');
    assert.equal((resolveSiteHref('board:pricing.html', '/', boards) as { route: string }).route, '/pricing');
    assert.deepEqual(resolveSiteHref('board:draft.html', '/', boards), { kind: 'missing', path: 'board:draft.html' });
    assert.deepEqual(resolveSiteHref('#top', '/', boards), { kind: 'anchor', hash: '#top' });
    assert.deepEqual(resolveSiteHref('https://example.com', '/', boards), { kind: 'external', href: 'https://example.com' });
    assert.deepEqual(resolveSiteHref('mailto:a@b.c', '/', boards), { kind: 'external', href: 'mailto:a@b.c' });
    assert.deepEqual(resolveSiteHref('/contact', '/', boards), { kind: 'missing', path: '/contact' });
  });

  it('accepts only the navigation script messages', () => {
    assert.deepEqual(parseSiteFrameMessage({ source: 'design-studio-site', type: 'height', height: 2400.4 }), {
      type: 'height',
      height: 2400,
    });
    assert.deepEqual(parseSiteFrameMessage({ source: 'design-studio-site', type: 'navigate', href: '/x' }), {
      type: 'navigate',
      href: '/x',
    });
    assert.deepEqual(parseSiteFrameMessage({ source: 'design-studio-site', type: 'scroll', top: 120.6 }), {
      type: 'scroll',
      top: 121,
    });
    assert.equal(parseSiteFrameMessage({ type: 'navigate', href: '/x' }), null);
    assert.equal(parseSiteFrameMessage('navigate'), null);
  });
});
