'use strict';
// design-board-renderer — renders design board thumbnails in colour themes (README.md has the contract).
//
//   node server.js [--port=8080] [--channel=chrome]   (--channel: local runs only)
//
// GET  /healthz                         200 once Chromium is running, else 503 (no auth)
// POST /designBoardRenderer/thumbnails  Argus batch envelope in and out (also under /api/batch/designBoardRenderer/…)
const http = require('http');
const crypto = require('crypto');
const { chromium } = require('playwright');
const { renderBoardThemes, launchBrowser } = require('./renderer');

const portArgument = process.argv.find(argument => argument.startsWith('--port='));
const PORT = portArgument ? Number(portArgument.slice('--port='.length)) : 8080;
const channelArgument = process.argv.find(argument => argument.startsWith('--channel='));
const CHANNEL = channelArgument ? channelArgument.slice('--channel='.length) : undefined;
const MAX_BODY_BYTES = 32 * 1024 * 1024;
const REQUEST_TIMEOUT_MS = 120_000;
const MAX_THEMES_PER_REQUEST = 50;
const ROUTE_PREFIXES = ['/api/batch/designBoardRenderer/', '/designBoardRenderer/'];

const integerFromEnv = (name, fallback) => {
  const value = Number.parseInt(process.env[name] ?? '', 10);
  return Number.isFinite(value) && value > 0 ? value : fallback;
};

const log = (level, message, fields = {}) =>
  process.stdout.write(JSON.stringify({ time: new Date().toISOString(), level, message, ...fields }) + '\n');

// ---- callers: token → class (prod / dev), constant-time compare; no tokens configured = auth off (local runs)
const tokenDigest = token => crypto.createHash('sha256').update(token).digest();
const TOKENS = ['PROD', 'DEV'].flatMap(callerClass =>
  (process.env[`RENDER_TOKENS_${callerClass}`] ?? '')
    .split(',')
    .map(token => token.trim())
    .filter(Boolean)
    .map(token => ({ digest: tokenDigest(token), callerClass: callerClass.toLowerCase() }))
);
const isAuthEnabled = TOKENS.length > 0;

const resolveCallerClass = request => {
  if (!isAuthEnabled) return 'dev';
  const presented = request.headers['x-render-token'];
  if (typeof presented !== 'string' || presented === '') return null;
  const presentedDigest = tokenDigest(presented);
  let callerClass = null;
  // compare against every token (no early exit): the time does not reveal which one matched
  for (const token of TOKENS) {
    if (crypto.timingSafeEqual(token.digest, presentedDigest)) callerClass = token.callerClass;
  }
  return callerClass;
};

// ---- per class: concurrent pages and a bounded queue (beyond it: 429 + Retry-After)
const QUEUE_MAX = integerFromEnv('RENDER_QUEUE_MAX', 20);
const lanes = {
  prod: { maxActive: integerFromEnv('RENDER_MAX_PAGES_PROD', 2), active: 0, waiting: [] },
  dev: { maxActive: integerFromEnv('RENDER_MAX_PAGES_DEV', 1), active: 0, waiting: [] },
};

const acquireSlot = (lane, signal) =>
  new Promise((resolve, reject) => {
    if (lane.active < lane.maxActive) {
      lane.active++;
      resolve();
      return;
    }
    const waiter = () => {
      lane.active++;
      resolve();
    };
    lane.waiting.push(waiter);
    signal.addEventListener('abort', () => {
      const index = lane.waiting.indexOf(waiter);
      if (index >= 0) lane.waiting.splice(index, 1);
      reject(new Error('timed out while queued'));
    }, { once: true });
  });

const releaseSlot = lane => {
  lane.active--;
  lane.waiting.shift()?.();
};

// ---- HTTP
const sendJson = (response, statusCode, body, headers = {}) => {
  const payload = JSON.stringify(body);
  response.writeHead(statusCode, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...headers });
  response.end(payload);
};
const sendError = (response, statusCode, error, headers = {}) => sendJson(response, statusCode, { status: statusCode, error }, headers);

const readBody = request =>
  new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    request.on('data', chunk => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error(`body larger than ${MAX_BODY_BYTES} bytes`), { statusCode: 413 }));
        request.destroy();
        return;
      }
      chunks.push(chunk);
    });
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });

/** @returns {{html: string, width: number, height: number, fullPage: boolean, themes: {themeHash: string, css: string}[]}} */
const validateThumbnailsJob = body => {
  if (!body || typeof body.html !== 'string' || body.html === '') throw Object.assign(new Error('html is required'), { statusCode: 400 });
  const themes = Array.isArray(body.themes) ? body.themes : [];
  if (themes.length > MAX_THEMES_PER_REQUEST) throw Object.assign(new Error(`at most ${MAX_THEMES_PER_REQUEST} themes`), { statusCode: 400 });
  for (const theme of themes) {
    if (!theme || typeof theme.themeHash !== 'string' || typeof theme.css !== 'string') {
      throw Object.assign(new Error('every theme needs themeHash and css'), { statusCode: 400 });
    }
  }
  return { html: body.html, width: Number(body.width) || 390, height: Number(body.height) || 844, fullPage: body.fullPage === true, themes };
};

let browser = null;

const jobs = {
  thumbnails: async (body, signal) => {
    const job = validateThumbnailsJob(body);
    const images = await renderBoardThemes(browser, job, { signal });
    return {
      images: images.map(image => ({
        themeHash: image.themeHash,
        full: image.full.toString('base64'),
        width: image.width,
        height: image.height,
      })),
    };
  },
};

const handleRender = async (request, response, jobName) => {
  const job = jobs[jobName];
  if (!job) return sendError(response, 404, `unknown job "${jobName}"`);
  const callerClass = resolveCallerClass(request);
  if (!callerClass) return sendError(response, 401, 'missing or unknown X-Render-Token');
  const lane = lanes[callerClass];
  if (lane.waiting.length >= QUEUE_MAX) {
    log('warn', 'queue full', { callerClass, queued: lane.waiting.length });
    return sendError(response, 429, 'renderer busy', { 'Retry-After': '30' });
  }
  const startedAt = Date.now();
  const abortController = new AbortController();
  const timer = setTimeout(() => abortController.abort(), REQUEST_TIMEOUT_MS);
  let hasSlot = false;
  try {
    const body = JSON.parse(await readBody(request));
    await acquireSlot(lane, abortController.signal);
    hasSlot = true;
    const queuedMs = Date.now() - startedAt;
    const data = await job(body, abortController.signal);
    sendJson(response, 200, { status: 200, data });
    log('info', 'rendered', { job: jobName, callerClass, queuedMs, totalMs: Date.now() - startedAt, images: data.images?.length });
  } catch (error) {
    const statusCode = abortController.signal.aborted ? 504 : (error.statusCode ?? (error instanceof SyntaxError ? 400 : 500));
    log(statusCode >= 500 ? 'error' : 'warn', 'render failed', { job: jobName, callerClass, statusCode, error: String(error.message ?? error) });
    if (!response.headersSent) sendError(response, statusCode, String(error.message ?? error));
  } finally {
    clearTimeout(timer);
    if (hasSlot) releaseSlot(lane);
  }
};

const server = http.createServer((request, response) => {
  const url = new URL(request.url ?? '/', 'http://renderer');
  if (request.method === 'GET' && url.pathname === '/healthz') {
    return browser?.isConnected() ? sendJson(response, 200, { status: 'ok' }) : sendJson(response, 503, { status: 'starting' });
  }
  const prefix = ROUTE_PREFIXES.find(candidate => url.pathname.startsWith(candidate));
  if (request.method === 'POST' && prefix) {
    return void handleRender(request, response, url.pathname.slice(prefix.length));
  }
  sendError(response, 404, 'not found');
});
server.requestTimeout = REQUEST_TIMEOUT_MS + 10_000;

(async () => {
  server.listen(PORT, () => log('info', 'listening', { port: PORT, auth: isAuthEnabled ? 'on' : 'off (no RENDER_TOKENS_*)', lanes: Object.fromEntries(Object.entries(lanes).map(([name, lane]) => [name, lane.maxActive])), queueMax: QUEUE_MAX }));
  browser = await launchBrowser(chromium, { channel: CHANNEL });
  browser.on('disconnected', () => {
    log('error', 'chromium disconnected — exiting so the orchestrator restarts the service');
    process.exit(1);
  });
  log('info', 'chromium ready', { version: browser.version() });
})().catch(error => {
  log('error', 'startup failed', { error: String(error.message ?? error) });
  process.exit(1);
});

const shutdown = async signal => {
  log('info', 'shutting down', { signal });
  server.close();
  await browser?.close().catch(() => {});
  process.exit(0);
};
process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
