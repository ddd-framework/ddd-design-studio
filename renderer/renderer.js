'use strict';
// Core of the design board renderer: one Chromium, one page per board render, the board loaded ONCE and every theme
// rendered by swapping the injected `<style data-design-theme>` in place (no reload). Used by server.js (the HTTP
// service).
//
// Network: every request a page makes is aborted (no egress at all; boards are self-contained). A Google Fonts
// stylesheet for Montserrat in a board is therefore replaced by the bundled files (fonts/, SIL OFL) as data: URLs —
// bundle the fonts your boards use the same way.
const fs = require('fs');
const path = require('path');

const FONT_WEIGHTS = { 400: 'Montserrat-Regular.ttf', 500: 'Montserrat-Medium.ttf', 600: 'Montserrat-SemiBold.ttf', 700: 'Montserrat-Bold.ttf' };
const THEME_STYLE_SELECTOR = 'style[data-design-theme]';

let fontFaceCss = null;
/** @returns {string} `<style>` with the bundled Montserrat weights as data: URLs (built once per process) */
const getFontFaceStyle = () => {
  if (fontFaceCss === null) {
    const faces = Object.entries(FONT_WEIGHTS).map(([weight, fileName]) => {
      const base64 = fs.readFileSync(path.join(__dirname, 'fonts', fileName)).toString('base64');
      return `@font-face{font-family:'Montserrat';font-style:normal;font-weight:${weight};font-display:block;src:url(data:font/ttf;base64,${base64}) format('truetype')}`;
    });
    fontFaceCss = `<style data-design-renderer-fonts>${faces.join('')}</style>`;
  }
  return fontFaceCss;
};

/** A theme block is the delivery endpoint's `<style data-design-theme>` element; plain CSS is wrapped into one. */
const toThemeStyleElement = css =>
  /^\s*<style\b/i.test(css) ? css : `<style data-design-theme>${css}</style>`;

/**
 * The board HTML as the canvas iframe shows it minus the theme: scripts removed (the delivery endpoint strips them
 * too) unless `keepScripts` (a canvas with board scripts), the bundled fonts first in <head>. The theme style is
 * inserted per theme afterwards.
 */
const prepareHtml = (html, keepScripts = false) => {
  const withoutScripts = keepScripts
    ? html
    : html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '').replace(/<script\b[^>]*\/>/gi, '');
  const headMatch = /<head[^>]*>/i.exec(withoutScripts);
  if (!headMatch) return getFontFaceStyle() + withoutScripts;
  const insertAt = headMatch.index + headMatch[0].length;
  return withoutScripts.slice(0, insertAt) + getFontFaceStyle() + withoutScripts.slice(insertAt);
};

/** Highest picture of a whole page (`fullPage`): a longer page is cut there. */
const MAXIMUM_FULL_PAGE_HEIGHT = 20000;

/** Time the board's scripts get after the load and after each theme swap before the picture (`scripts`). */
const SCRIPT_SETTLE_MILLISECONDS = 300;

/**
 * @param {import('playwright').Browser} browser
 * @param {{html: string, width: number, height: number, fullPage?: boolean, scripts?: boolean, themes: {themeHash: string, css: string}[]}} job
 *   `fullPage`: the whole page at `width` — the picture is as high as the page (at least `height`, at most
 *   MAXIMUM_FULL_PAGE_HEIGHT); without it exactly `width` × `height`. `scripts`: the board's own scripts run (still
 *   without network) and get SCRIPT_SETTLE_MILLISECONDS before each picture; without it they are removed
 * @param {{signal?: AbortSignal}} [options]
 * @returns {Promise<{themeHash: string, full: Buffer, width: number, height: number}[]>}
 */
const renderBoardThemes = async (browser, job, options = {}) => {
  const width = Math.max(1, Math.min(4000, Math.round(job.width || 390)));
  const height = Math.max(1, Math.min(20000, Math.round(job.height || 844)));
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1 });
  const abortListener = () => context.close().catch(() => {});
  options.signal?.addEventListener('abort', abortListener, { once: true });
  try {
    // no network at all: everything that is not inline / data: is aborted
    await context.route('**/*', route => route.abort('blockedbyclient'));
    const page = await context.newPage();
    // the first theme is part of the initial document (same position as the delivery endpoint: first in <head>)
    const themes = job.themes.length ? job.themes : [{ themeHash: '', css: '' }];
    const prepared = prepareHtml(job.html, job.scripts === true);
    await page.setContent(prepared, { waitUntil: 'load', timeout: 60_000 });
    const images = [];
    for (const theme of themes) {
      if (options.signal?.aborted) throw new Error('aborted');
      await page.evaluate(
        ([selector, styleHtml]) => {
          for (const element of document.querySelectorAll(selector)) element.remove();
          if (styleHtml) document.head.insertAdjacentHTML('afterbegin', styleHtml);
        },
        [THEME_STYLE_SELECTOR, theme.css ? toThemeStyleElement(theme.css) : '']
      );
      await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all([...document.images].map(image => (image.complete ? null : image.decode().catch(() => null))));
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      });
      if (job.scripts === true) await page.waitForTimeout(SCRIPT_SETTLE_MILLISECONDS);
      let pictureHeight = height;
      if (job.fullPage) {
        const pageHeight = await page.evaluate(() =>
          Math.ceil(Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0))
        );
        pictureHeight = Math.max(height, Math.min(MAXIMUM_FULL_PAGE_HEIGHT, pageHeight || height));
        // a viewport as high as the page: elements sized in vh keep the device's height only in the first screen
        // otherwise, so the page is measured at the device height and photographed in one piece
      }
      const full = await page.screenshot({ clip: { x: 0, y: 0, width, height: pictureHeight }, fullPage: job.fullPage === true, type: 'png' });
      images.push({ themeHash: theme.themeHash, full, width, height: pictureHeight });
    }
    return images;
  } finally {
    options.signal?.removeEventListener('abort', abortListener);
    await context.close().catch(() => {});
  }
};

/**
 * Launches the Chromium all renders share: the playwright image's bundled Chromium; locally `channel: 'chrome'` uses
 * the installed Google Chrome when the Playwright browsers are not downloaded.
 */
const launchBrowser = async (chromium, { channel } = {}) =>
  chromium.launch({ args: ['--disable-dev-shm-usage'], ...(channel ? { channel } : {}) });

module.exports = { renderBoardThemes, launchBrowser, prepareHtml, toThemeStyleElement };
