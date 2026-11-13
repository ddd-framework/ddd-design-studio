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
| `POST /designBoardRenderer/<job>` | job `thumbnails`; also accepted under `/api/batch/designBoardRenderer/<job>` (for a reverse-proxy route of the app) |
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
  "html": "<!doctype html>… the board HTML (scripts are removed, as in the canvas iframe)",
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
variable holding the token), `concurrency`, `rendererVersion`. See the module README for the Messenger transport and
the worker.
