'use strict';
// Core of the design board renderer: one Chromium, one page per board render, the board loaded ONCE and every theme
// rendered by swapping the injected `<style data-design-theme>` in place (no reload); a board as a vector graphic
// (renderBoardVector: PDF from Chromium, SVG from pdftocairo). Used by server.js (the HTTP service).
//
// Network: every request a page makes is aborted (no egress at all; boards are self-contained). A Google Fonts
// stylesheet for Montserrat in a board is therefore replaced by the bundled files (fonts/, SIL OFL) as data: URLs —
// bundle the fonts your boards use the same way.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile } = require('child_process');

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

/** Pixels whose colour differs by more than this sum over R, G and B count as different in the comparison. */
const DIFFERING_PIXEL_THRESHOLD = 48;

/** Longest time the PDF → SVG conversion may take. */
const CONVERSION_TIMEOUT_MILLISECONDS = 60_000;

/** CSS pixels → PDF points (the unit of the converted SVG's viewBox). */
const POINTS_PER_PIXEL = 0.75;

/** @returns {Promise<void>} */
const runPdfToSvg = (pdfPath, svgPath) =>
  new Promise((resolve, reject) => {
    execFile('pdftocairo', ['-svg', '-f', '1', '-l', '1', pdfPath, svgPath], { timeout: CONVERSION_TIMEOUT_MILLISECONDS }, error =>
      error ? reject(new Error(`pdftocairo failed: ${error.message}`)) : resolve()
    );
  });

/**
 * The converted SVG with the numbers of its path data cut to two decimals (points: 0.01 pt is far below a pixel) —
 * about a quarter smaller. Only `d` attributes: a transform's scale (an embedded picture of thousands of pixels) needs
 * its full precision.
 */
const compactSvgNumbers = svg =>
  svg.replace(/(\sd=")([^"]*)"/g, (match, attributeStart, pathData) =>
    `${attributeStart}${pathData.replace(/(\d+\.\d\d)\d+/g, '$1').replace(/(\d+)\.00(?!\d)/g, '$1')}"`
  );

/**
 * The converted SVG sized in CSS pixels (as an `<img>` shows it without width / height) and cut to the region: the root
 * element's width / height become the region's pixels, its viewBox the region in points. Content outside the region
 * stays in the file but is not shown. With `pageBackgroundColor` a rectangle of that colour lies under everything.
 */
const sizeSvgToRegion = (svg, region, pageBackgroundColor = null) => {
  const rootMatch = /<svg\b[^>]*>/.exec(svg);
  if (!rootMatch) throw new Error('the conversion returned no svg element');
  const viewBoxValues = [region.x, region.y, region.width, region.height].map(value => +(value * POINTS_PER_PIXEL).toFixed(3));
  const viewBox = viewBoxValues.join(' ');
  const root = rootMatch[0]
    .replace(/\s(width|height|viewBox)="[^"]*"/g, '')
    .replace(/^<svg\b/, `<svg width="${region.width}" height="${region.height}" viewBox="${viewBox}"`);
  // the page's background under everything: the print can leave a hairline uncovered at the right and bottom edge,
  // which shows white on a dark board
  const backgroundRect = pageBackgroundColor
    ? `\n<rect x="${viewBoxValues[0]}" y="${viewBoxValues[1]}" width="${viewBoxValues[2]}" height="${viewBoxValues[3]}" fill="${pageBackgroundColor}"/>`
    : '';
  return svg.slice(0, rootMatch.index) + root + backgroundRect + svg.slice(rootMatch.index + rootMatch[0].length);
};

/**
 * Compares the raster picture with the SVG as a browser shows it in an `<img>`: the share of differing pixels and a
 * comparison picture of three panels — raster, SVG, and the raster greyed with the differing pixels in red.
 * @returns {Promise<{differingPixelRatio: number, comparison: Buffer}>}
 */
const compareSvgWithRaster = async (context, rasterPng, svg, width, height) => {
  const page = await context.newPage();
  try {
    await page.setContent('<!doctype html><html><body style="margin:0"></body></html>');
    const result = await page.evaluate(
      async ([rasterUrl, svgUrl, width, height, threshold]) => {
        const loadImage = source =>
          new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('an image of the comparison did not load'));
            image.src = source;
          });
        const [rasterImage, svgImage] = await Promise.all([loadImage(rasterUrl), loadImage(svgUrl)]);
        const readPixels = image => {
          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          const context2d = canvas.getContext('2d');
          context2d.fillStyle = '#fff';
          context2d.fillRect(0, 0, width, height);
          context2d.drawImage(image, 0, 0, width, height);
          return context2d.getImageData(0, 0, width, height);
        };
        const rasterPixels = readPixels(rasterImage);
        const svgPixels = readPixels(svgImage);
        const differencePixels = new ImageData(width, height);
        let differingPixelCount = 0;
        for (let offset = 0; offset < rasterPixels.data.length; offset += 4) {
          const difference = Math.abs(rasterPixels.data[offset] - svgPixels.data[offset])
            + Math.abs(rasterPixels.data[offset + 1] - svgPixels.data[offset + 1])
            + Math.abs(rasterPixels.data[offset + 2] - svgPixels.data[offset + 2]);
          const grey = Math.round(0.3 * rasterPixels.data[offset] + 0.59 * rasterPixels.data[offset + 1] + 0.11 * rasterPixels.data[offset + 2]);
          const isDiffering = difference > threshold;
          if (isDiffering) differingPixelCount++;
          differencePixels.data[offset] = isDiffering ? 230 : 200 + grey / 5;
          differencePixels.data[offset + 1] = isDiffering ? 30 : 200 + grey / 5;
          differencePixels.data[offset + 2] = isDiffering ? 30 : 200 + grey / 5;
          differencePixels.data[offset + 3] = 255;
        }
        const gap = 12;
        const comparisonCanvas = document.createElement('canvas');
        comparisonCanvas.width = width * 3 + gap * 2;
        comparisonCanvas.height = height;
        const comparisonContext = comparisonCanvas.getContext('2d');
        comparisonContext.fillStyle = '#fff';
        comparisonContext.fillRect(0, 0, comparisonCanvas.width, height);
        comparisonContext.putImageData(rasterPixels, 0, 0);
        comparisonContext.putImageData(svgPixels, width + gap, 0);
        comparisonContext.putImageData(differencePixels, (width + gap) * 2, 0);
        return {
          differingPixelRatio: differingPixelCount / (width * height),
          comparisonDataUrl: comparisonCanvas.toDataURL('image/png'),
        };
      },
      [
        'data:image/png;base64,' + rasterPng.toString('base64'),
        'data:image/svg+xml;base64,' + Buffer.from(svg, 'utf8').toString('base64'),
        width,
        height,
        DIFFERING_PIXEL_THRESHOLD,
      ]
    );
    return {
      differingPixelRatio: result.differingPixelRatio,
      comparison: Buffer.from(result.comparisonDataUrl.slice(result.comparisonDataUrl.indexOf(',') + 1), 'base64'),
    };
  } finally {
    await page.close().catch(() => {});
  }
};

/** Scale the backgrounds that are drawn as pictures before the PDF are rendered at (sharp on a 2× screen). */
const RASTERIZED_BACKGROUND_SCALE = 2;

/**
 * Backgrounds a PDF cannot hold as vectors without breaking the SVG — conic gradients: Chromium writes them as a shading
 * group, and the SVG conversion then wraps the WHOLE page into a compositing filter that browsers draw with light
 * boxes and edge lines. Each such background is drawn on its own (its element's size and rounded corners, transparent
 * around it, RASTERIZED_BACKGROUND_SCALE) in a second page and put back as a picture; the element's other properties
 * stay. Returns how many backgrounds were replaced.
 * @returns {Promise<number>}
 */
const rasterizeConicGradientBackgrounds = async (context, page) => {
  const backgrounds = await page.evaluate(() => {
    const found = [];
    let index = 0;
    for (const element of document.querySelectorAll('*')) {
      const style = getComputedStyle(element);
      if (!style.backgroundImage.includes('conic-gradient(')) continue;
      const rect = element.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) continue;
      const marker = `background-${index++}`;
      element.setAttribute('data-design-renderer-background', marker);
      found.push({
        marker,
        width: rect.width,
        height: rect.height,
        backgroundImage: style.backgroundImage,
        backgroundSize: style.backgroundSize,
        backgroundPosition: style.backgroundPosition,
        backgroundRepeat: style.backgroundRepeat,
        backgroundOrigin: style.backgroundOrigin,
        backgroundClip: style.backgroundClip,
        borderRadius: style.borderRadius,
        borderWidth: style.borderWidth,
        borderStyle: style.borderStyle,
        borderColor: 'transparent',
      });
    }
    return found;
  });
  if (backgrounds.length === 0) return 0;
  // a context of its own: the board's context renders at scale 1
  const backgroundContext = await context.browser().newContext({
    viewport: { width: 100, height: 100 },
    deviceScaleFactor: RASTERIZED_BACKGROUND_SCALE,
  });
  try {
    await backgroundContext.route('**/*', route => route.abort('blockedbyclient'));
    const backgroundPage = await backgroundContext.newPage();
    for (const background of backgrounds) {
      const width = Math.ceil(background.width);
      const height = Math.ceil(background.height);
      await backgroundPage.setContent(
        '<!doctype html><html><body style="margin:0;background:transparent"><div id="background"></div></body></html>'
      );
      await backgroundPage.evaluate(([background, width, height]) => {
        const element = document.getElementById('background');
        Object.assign(element.style, {
          boxSizing: 'border-box',
          width: `${background.width}px`,
          height: `${background.height}px`,
          backgroundImage: background.backgroundImage,
          backgroundSize: background.backgroundSize,
          backgroundPosition: background.backgroundPosition,
          backgroundRepeat: background.backgroundRepeat,
          backgroundOrigin: background.backgroundOrigin,
          backgroundClip: background.backgroundClip,
          borderRadius: background.borderRadius,
          borderWidth: background.borderWidth,
          borderStyle: background.borderStyle,
          borderColor: background.borderColor,
        });
        document.documentElement.style.width = `${width}px`;
        document.documentElement.style.height = `${height}px`;
      }, [background, width, height]);
      const picture = await backgroundPage.screenshot({
        clip: { x: 0, y: 0, width: background.width, height: background.height },
        omitBackground: true,
        type: 'png',
        scale: 'device',
      });
      await page.evaluate(([marker, pictureUrl]) => {
        const element = document.querySelector(`[data-design-renderer-background="${marker}"]`);
        if (!element) return;
        element.style.setProperty('background-image', `url("${pictureUrl}")`, 'important');
        element.style.setProperty('background-size', '100% 100%', 'important');
        element.style.setProperty('background-position', '0 0', 'important');
        element.style.setProperty('background-repeat', 'no-repeat', 'important');
        element.style.setProperty('background-origin', 'border-box', 'important');
        element.style.setProperty('background-clip', 'border-box', 'important');
        element.removeAttribute('data-design-renderer-background');
      }, [background.marker, 'data:image/png;base64,' + picture.toString('base64')]);
    }
  } finally {
    await backgroundContext.close().catch(() => {});
  }
  await page.evaluate(async () => {
    await Promise.all(
      [...document.querySelectorAll('[style*="data:image/png"]')].map(
        element =>
          new Promise(resolve => {
            const match = /url\("([^"]+)"\)/.exec(element.style.backgroundImage);
            if (!match) return resolve();
            const image = new Image();
            image.onload = image.onerror = () => resolve();
            image.src = match[1];
          })
      )
    );
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
  return backgrounds.length;
};

/** JPEG quality of a picture without transparency that the vector job scales down before the print. */
const DOWNSCALED_PICTURE_JPEG_QUALITY = 0.85;

/**
 * Pictures far larger than they are shown (a 320 px photo in a 42 px circle): the PDF — and so the SVG — would embed
 * them at their own size, as PNG. Each `<img>` whose natural size is more than RASTERIZED_BACKGROUND_SCALE times its
 * shown size is replaced by a copy at that scale (its shown size fixed first, so the layout does not move): JPEG when it
 * has no transparent pixel, else PNG. Returns how many pictures were replaced.
 * @returns {Promise<number>}
 */
const downscaleOversizedPictures = page =>
  page.evaluate(
    ([scale, jpegQuality]) => {
      let replacedCount = 0;
      for (const image of document.querySelectorAll('img')) {
        const rect = image.getBoundingClientRect();
        if (!image.complete || image.naturalWidth === 0 || rect.width < 1 || rect.height < 1) continue;
        const factor = Math.min(1, scale * Math.max(rect.width / image.naturalWidth, rect.height / image.naturalHeight));
        if (factor > 0.5) continue;
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * factor));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * factor));
        const context2d = canvas.getContext('2d');
        context2d.drawImage(image, 0, 0, canvas.width, canvas.height);
        let pixels;
        try {
          pixels = context2d.getImageData(0, 0, canvas.width, canvas.height).data;
        } catch {
          continue; // a picture the canvas may not read stays as it is
        }
        let hasTransparency = false;
        for (let offset = 3; offset < pixels.length; offset += 4) {
          if (pixels[offset] < 255) {
            hasTransparency = true;
            break;
          }
        }
        image.style.setProperty('width', `${rect.width}px`, 'important');
        image.style.setProperty('height', `${rect.height}px`, 'important');
        image.removeAttribute('srcset');
        image.src = hasTransparency ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', jpegQuality);
        replacedCount++;
      }
      return replacedCount;
    },
    [RASTERIZED_BACKGROUND_SCALE, DOWNSCALED_PICTURE_JPEG_QUALITY]
  );

/**
 * One board in one theme as a vector graphic: Chromium prints the board as a one-page PDF (screen media, backgrounds
 * on) and `pdftocairo` (poppler) converts it to SVG — text as glyph outlines, pictures embedded, no external reference,
 * so the file shows in an `<img>`. Effects a PDF cannot hold (backdrop-filter, some blend modes) are lost: the answer
 * carries the raster picture of the same version, the share of differing pixels and a comparison picture.
 * @param {import('playwright').Browser} browser
 * @param {{html: string, width: number, height: number, fullPage?: boolean, scripts?: boolean,
 *   theme?: {themeHash: string, css: string}|null, region?: {x: number, y: number, width: number, height: number}|null}} job
 *   `region`: a part of the board in its pixels (clamped to the board); without it the whole board
 * @param {{signal?: AbortSignal}} [options]
 * @returns {Promise<{svg: string, png: Buffer, comparison: Buffer, differingPixelRatio: number, width: number, height: number}>}
 */
const renderBoardVector = async (browser, job, options = {}) => {
  const width = Math.max(1, Math.min(4000, Math.round(job.width || 390)));
  const height = Math.max(1, Math.min(20000, Math.round(job.height || 844)));
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1 });
  const abortListener = () => context.close().catch(() => {});
  options.signal?.addEventListener('abort', abortListener, { once: true });
  const workDirectory = await fs.promises.mkdtemp(path.join(os.tmpdir(), 'design-board-vector-'));
  try {
    await context.route('**/*', route => route.abort('blockedbyclient'));
    const page = await context.newPage();
    const themeStyle = job.theme && job.theme.css ? toThemeStyleElement(job.theme.css) : '';
    let prepared = prepareHtml(job.html, job.scripts === true);
    if (themeStyle) {
      const headMatch = /<head[^>]*>/i.exec(prepared);
      prepared = headMatch
        ? prepared.slice(0, headMatch.index + headMatch[0].length) + themeStyle + prepared.slice(headMatch.index + headMatch[0].length)
        : themeStyle + prepared;
    }
    await page.setContent(prepared, { waitUntil: 'load', timeout: 60_000 });
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
    }
    const region = clampRegion(job.region, width, pictureHeight);
    if (options.signal?.aborted) throw new Error('aborted');
    const png = await page.screenshot({ clip: region, fullPage: job.fullPage === true, type: 'png' });
    await page.emulateMedia({ media: 'screen' });
    // the colour the board shows at its edge: the background of the element at the bottom-right corner, else of its
    // nearest ancestor with one, else the root's, else white (the print can leave a hairline uncovered there)
    const pageBackgroundColor = await page.evaluate(() => {
      const isVisible = color => Boolean(color) && color !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(color);
      let element = document.elementFromPoint(window.innerWidth - 1, window.innerHeight - 1);
      while (element) {
        const color = getComputedStyle(element).backgroundColor;
        if (isVisible(color)) return color;
        element = element.parentElement;
      }
      return 'rgb(255, 255, 255)';
    });
    // after the raster picture: the comparison shows what the replacement changes
    await rasterizeConicGradientBackgrounds(context, page);
    if ((await downscaleOversizedPictures(page)) > 0) {
      await page.evaluate(async () => {
        await Promise.all([...document.images].map(image => (image.complete ? null : image.decode().catch(() => null))));
      });
    }
    // the page exactly as large as the board: without it Chromium rounds the paper size up to a hundredth of an inch
    await page.addStyleTag({ content: `@page{size:${width}px ${pictureHeight}px;margin:0}` });
    const pdf = await page.pdf({
      preferCSSPageSize: true,
      width: `${width}px`,
      height: `${pictureHeight}px`,
      printBackground: true,
      pageRanges: '1',
      margin: { top: '0', right: '0', bottom: '0', left: '0' },
    });
    const pdfPath = path.join(workDirectory, 'board.pdf');
    const svgPath = path.join(workDirectory, 'board.svg');
    await fs.promises.writeFile(pdfPath, pdf);
    if (options.signal?.aborted) throw new Error('aborted');
    await runPdfToSvg(pdfPath, svgPath);
    const svg = sizeSvgToRegion(compactSvgNumbers(await fs.promises.readFile(svgPath, 'utf8')), region, pageBackgroundColor);
    const { differingPixelRatio, comparison } = await compareSvgWithRaster(context, png, svg, region.width, region.height);
    return { svg, png, comparison, differingPixelRatio, width: region.width, height: region.height };
  } finally {
    options.signal?.removeEventListener('abort', abortListener);
    await context.close().catch(() => {});
    await fs.promises.rm(workDirectory, { recursive: true, force: true }).catch(() => {});
  }
};

/**
 * The region inside the board (whole pixels, at least 1 × 1); the whole board without a region.
 * @returns {{x: number, y: number, width: number, height: number}}
 */
const clampRegion = (region, boardWidth, boardHeight) => {
  if (!region) return { x: 0, y: 0, width: boardWidth, height: boardHeight };
  const x = Math.max(0, Math.min(boardWidth - 1, Math.round(region.x)));
  const y = Math.max(0, Math.min(boardHeight - 1, Math.round(region.y)));
  return {
    x,
    y,
    width: Math.max(1, Math.min(boardWidth - x, Math.round(region.width))),
    height: Math.max(1, Math.min(boardHeight - y, Math.round(region.height))),
  };
};

/**
 * Launches the Chromium all renders share: the playwright image's bundled Chromium; locally `channel: 'chrome'` uses
 * the installed Google Chrome when the Playwright browsers are not downloaded.
 */
const launchBrowser = async (chromium, { channel } = {}) =>
  chromium.launch({ args: ['--disable-dev-shm-usage'], ...(channel ? { channel } : {}) });

module.exports = { renderBoardThemes, renderBoardVector, launchBrowser, prepareHtml, toThemeStyleElement, sizeSvgToRegion, compactSvgNumbers, clampRegion };
