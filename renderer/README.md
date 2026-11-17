# Example thumbnail renderer

The design studio shows thumbnails on a zoomed-out canvas when a renderer is configured
(`config/app/DesignStudio/renderer.php`, `enabled`). The renderer is any HTTP service that implements the contract
below; this folder is a working example: a stateless Node + Playwright/Chromium service with no database and no
secrets besides the caller tokens. It needs **no network access** — every request a page makes is aborted, so boards
must be self-contained (fonts are bundled, see `renderer.js`).

The module calls it through `ArgusDesignBoardRenderedThumbnails` from the Messenger handler
`RenderDesignStudioThumbnailsHandler`. Without a renderer the canvas shows frames with titles when zoomed out and the
live boards when zoomed in.

## Contract

| | |
|---|---|
| Port | `8080` |
| `GET /healthz` | `200 {"status":"ok"}` once Chromium is running, `503` before; no auth (readiness + liveness) |
| `POST /designBoardRenderer/<job>` | jobs `thumbnails` and `vector` (renderer 1.2.0); also accepted under `/api/batch/designBoardRenderer/<job>` (for a reverse-proxy route of the app) |
| Auth | header `X-Render-Token`; compared in constant time against `RENDER_TOKENS_PROD` / `RENDER_TOKENS_DEV`; unknown or missing → `401`. With neither variable set auth is **off** (local runs only) |
| Concurrency | per caller class: `RENDER_MAX_PAGES_PROD` (default 2) / `RENDER_MAX_PAGES_DEV` (default 1) renders at once; `RENDER_QUEUE_MAX` (default 20) waiting per class, beyond → `429` + `Retry-After: 30` |
| Limits | body ≤ 32 MB (`413`), 120 s per request incl. queueing (`504`), ≤ 50 themes per request, board ≤ 4000 × 20000 px |
| Logs | JSON lines on stdout (`time`, `level`, `message`, fields) |

The two caller classes let one instance serve a production application and development environments without the
latter starving the former. A single application sets only `RENDER_TOKENS_PROD`.

### `POST /designBoardRenderer/thumbnails`

Request (JSON body):

```json
{
  "html": "<!doctype html>… the board HTML (scripts are removed, unless `scripts` is set)",
  "width": 390,
  "height": 844,
  "themes": [
    { "themeHash": "<sha256 of the theme block>", "css": "<style data-design-theme>:root{--p-500:…}</style>" }
  ]
}
```

Optional `"fullPage": true` (sent by the studio for the device frames of a responsive board, never for a fixed board):
the page is laid out at `width` × `height` (the device viewport) and photographed as a whole — the picture's height is
the page height (`document.documentElement.scrollHeight`), at least `height` and at most 20000; `width` stays. Each
image's `width` / `height` in the response are the picture's size. Without the field (or `false`) the picture is exactly
`width` × `height`, as before. A renderer that does not know the field renders `width` × `height`: the frame then shows
one screen of the page.

Optional `"scripts": true` (sent by the studio for the boards of a canvas with board scripts, renderer 1.1.0): the
board's `<script>` elements stay and run — still without network; the studio sends the canvas' script files inlined as
`data:` URLs — and the picture is taken 300 ms after the load (and after each theme swap). Without the field the
scripts are removed. A renderer that does not know the field removes them as well: the picture then shows the board
before its scripts ran.

The board is loaded **once**; each theme is applied by replacing the `<style data-design-theme>` element (first in
`<head>`, exactly where the studio's content route injects it) — no reload. `css` may also be plain CSS (it is
wrapped). An empty `themes` list renders the board as authored.

Response (Argus batch envelope):

```json
{ "status": 200, "data": { "images": [ { "themeHash": "…", "full": "<PNG base64>", "width": 390, "height": 844 } ] } }
```

Errors: `{ "status": <code>, "error": "<message>" }` with the same HTTP status (400 invalid body, 401, 404 unknown job,
413, 429, 500, 504). The studio treats 401 / 429 / 502 / 504 and transport errors as "try again later" (thumbnails
stay as they are). The reduced thumbnail is derived by the studio from `full`.

### `POST /designBoardRenderer/vector`

One board in one theme as a vector graphic (renderer 1.2.0). Chromium prints the board as a one-page PDF exactly as large
as the board (screen media, backgrounds on); `pdftocairo` (poppler-utils, installed in the image) converts it to SVG.

Request (JSON body): `html`, `width`, `height`, `fullPage`, `scripts` as for `thumbnails`; `theme` (optional, one
`{ "themeHash", "css" }`); `region` (optional, `{ "x", "y", "width", "height" }` in board pixels, clamped to the board —
the part of the board the SVG shows).

Response:

```json
{ "status": 200, "data": {
  "themeHash": "…", "svg": "<SVG base64>", "png": "<PNG base64: the raster picture of the same region>",
  "comparison": "<PNG base64: raster | SVG as an <img> shows it | differing pixels in red>",
  "differingPixelRatio": 0.0533, "width": 390, "height": 844 } }
```

The SVG needs nothing outside itself, so it shows in an `<img>`: text is drawn as glyph outlines (no font needed, not
selectable), pictures are embedded, the root carries `width` / `height` in CSS pixels and a `viewBox` in points (the
region). What a PDF cannot hold is lost or simplified — `backdrop-filter` (glass), some blend modes and filters; edges
can sit 1–2 px apart from the raster picture. Conic gradients are drawn as pictures (2×) before the print (renderer
1.2.1): as PDF shadings they made the conversion wrap the whole page into a compositing filter that browsers draw with
light boxes and edge lines. A rectangle in the colour of the board's edge lies under everything, so a hairline the
print leaves uncovered does not show white on a dark board. A picture shown far smaller than it is (a 320 px photo in a 42 px
circle) is scaled to twice its shown size before the print (renderer 1.2.2) — JPEG without transparency, else PNG —
so the SVG does not embed it at its own size as PNG. `differingPixelRatio` is the share of pixels whose colour differs by more
than 48 (sum over R, G, B) between the raster picture and the SVG; the comparison picture shows where. Photos, maps and
gradients stay raster inside the SVG: such a board's SVG is larger than its PNG or WebP. The SVG carries no element ids
of the board (the PDF path loses the document structure).

## Environment

| Variable | Default | |
|---|---|---|
| `RENDER_TOKENS_PROD` | — | comma-separated tokens of the production caller(s) |
| `RENDER_TOKENS_DEV` | — | comma-separated tokens of development callers |
| `RENDER_MAX_PAGES_PROD` | 2 | parallel renders for production callers |
| `RENDER_MAX_PAGES_DEV` | 1 | parallel renders for development callers |
| `RENDER_QUEUE_MAX` | 20 | waiting requests per class before 429 |

Nothing else; no secrets in the image.

## Build and run

```bash
docker build -t design-board-renderer renderer/
docker run --rm -p 8080:8080 -e RENDER_TOKENS_PROD=<token> --tmpfs /tmp design-board-renderer
curl -s localhost:8080/healthz
```

- `Dockerfile`: `mcr.microsoft.com/playwright:v1.64.1-noble`, pinned by digest; the npm `playwright` version in
  `package.json` must match the image tag (bump both together, and change `rendererVersion` in the studio's
  `renderer.php` so every thumbnail is rendered anew).
- Runs as `pwuser` (non-root); works with a read-only root filesystem when `/tmp` is writable (Chromium profile and
  crash files; `HOME` / `XDG_*` point there).
- Resources: about 1–2 CPU and a 2–3 GB memory limit for three parallel pages; `/dev/shm` is not needed
  (`--disable-dev-shm-usage`).
- Egress: none. Ingress: only the application (its workers call the endpoint).
- Chromium crashing ends the process (exit 1) so the orchestrator restarts it.

Without Docker (Playwright installed, e.g. `npm install` in this folder and an installed Google Chrome):

```bash
node server.js --port=18080 --channel=chrome   # auth off without RENDER_TOKENS_*
```

## Wiring in the application

`config/app/DesignStudio/renderer.php` (an application file replaces the module default as a whole): `enabled` true,
`endpoint` (`POST:<absolute URL>` or a path relative to the Argus base URL), `tokenEnv` (name of the environment
variable holding the token), `timeoutSeconds`, `rendererVersion`. Pictures rendered at once = consumer processes of
the render transport. See the module README for the Messenger transport and
the worker.
