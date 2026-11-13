# Changelog

## 0.6.1

- **A stuck render request blocks for 5 minutes, not 20:** every run (a redelivered one too), every stored thumbnail,
  every retry and every watching look now writes the request's modified time. Before, the partial writes left it
  untouched, so `isStalled()` counted from the creation and a request whose shard died kept the canvas for 20 minutes.
  An open request without a write for `STALLED_AFTER_SECONDS` (now 300) is replaced when the canvas is opened.
- **A failing object store is visible and retried:** a thumbnail that cannot be stored puts the request into
  `WAITING_FOR_RENDERER` with the reason in `lastError` and retries with backoff, instead of throwing out of the run.
- The longest retry delay is 240 seconds (was 600), below the stall limit.

## 0.6.0

- **Board scripts:** a canvas with `'scripts' => true` in `DesignStudio.projects` keeps its boards' inline `<script>`
  elements and script files of its `assets/` folder (`assets/*.js`, a new asset type). They run in canvas tiles,
  device frames, the focus view, present mode and the site view, next to the site navigation script. The CSP admits
  `'unsafe-inline'` and the canvas' asset path only (`connect-src 'none'`, `form-action 'none'`); the iframe is
  `sandbox="allow-scripts"`, never `allow-same-origin`. Default `false`: every other canvas is unchanged.
- **Thumbnails after the scripts:** the renderer gets `"scripts": true` for such boards; renderer 1.1.0 runs them and
  takes the picture 300 ms after the load (an older renderer shows the state before them). Their thumbnails have keys
  of their own.
- Board view: `runsScripts`; board route: query `scripts` (part of the URL only).

## 0.5.2

- **Focus view and present mode run a responsive board as on the device:** the iframe is one screen (device width ×
  height) and the page scrolls inside it, as in the site view. Before, the iframe was as tall as the whole page, so
  `position: fixed` / `sticky` elements (a phone dock, a sticky header) sat at the end of the page and `vh` units were
  page-relative. Pins, new pins and the marked region follow the page's scroll position (reported by the board route's
  `site=1` script). Fixed boards are unchanged.

## 0.5.1

- `app:design-studio:thumbnails` counts pictures: a responsive board has one per device frame ("N of M pictures have a
  thumbnail (B boards, F device frames …)"); before, it subtracted frame pictures from the board count.
- The site view opens a page in its widest device frame (a website is looked at on a desktop first), not in its first
  frame; a device picked in the site view stays while navigating.

## 0.5.0

**Site view: a canvas as a clickable website.**

- `DesignStudioSiteScreen` (npm): the boards with a `route` are the pages, one at a time, full width in a device
  (picker, rotation, free width, device frame), one URL per page through the new optional `paths.site(projectKey,
  canvasKey, route)` — shareable, browser back / forward. The canvas toolbar gets "Open as website" (at the selected
  board's page, else `/`) when the application sets `paths.site`.
- Links inside a page: `/path`, relative `path` and `board:<file>` open that page, `#anchor` scrolls, external links
  are not followed, a link without a page says so (texts `sitePageMissing`, `siteExternalLink`, `openAsWebsite`).
- Board route `?site=1`: the sandboxed board with the studio's navigation script, admitted by its hash
  (`script-src 'sha256-…'`, `sandbox allow-scripts`, opaque origin); the board's own scripts stay removed, inline
  handlers blocked. It reports link clicks, the scroll position and the page height by `postMessage`.
- Comments in the site view: pins placed and shown on the page (viewport and page position as on the canvas), the
  same threads.
- A route names one board: a second board with the same route loses it, with a canvas warning.

## 0.4.1

- **Canvas previews.** Each thumbnail gets a preview copy, at most 640 px wide, WebP (JPEG where Imagick has no WebP),
  stored next to it under the same key (`<key>-w640.webp`) when the thumbnail is stored; older thumbnails get it on
  their first request. The canvas shows the 120 px copy far out, the preview at middle zoom, and the full PNG only when
  a board (or a device frame) is drawn wider than 800 px. A 1440 × 7000 page is a few hundred kilobytes instead of
  megabytes. Route `GET …/thumbnails/{key}?size=preview`; the canvas view carries `thumbnailPreviewUrl` on boards and
  frames.
- **Loading.** A board card shows its title and "Loading…" (text `thumbnailLoading`) on a light surface until its
  first picture arrives, and the picture shown last stays until the next zoom level's picture has loaded.

## 0.4.0

**Responsive boards.** A board whose layout adapts to the viewport width is shown in device frames.

- **Marker.** `"responsive": true` on a board or on the canvas in canvas.json, or `<meta name="design-responsive">` in
  the board file. Unmarked boards stay fixed, as before.
- **Devices.** New config `DesignStudio.devices` (`config/app/DesignStudio/devices.php`): the presets (Desktop 1920 /
  1440, Laptop 1280, iPad Pro 12.9" and iPad 10.9" landscape / portrait, iPhone 16 Pro Max, iPhone 16 / 15, iPhone
  SE, Android) and `defaultFrameDeviceKeys`. `"devices": [...]` on a board or the canvas picks the frames.
- **Canvas.** One frame per device side by side (same file, version, status, code); a toolbar switch draws one screen
  or the whole page. The canvas view carries `devices`, a board view `isResponsive`, `frames` (device, width, height,
  page height, thumbnails) and `route`.
- **Focus view and present mode.** Device picker, landscape / portrait, a free width by a drag handle, device frame
  switch; scaled down when wider than the screen; the viewport stays while stepping through boards.
- **Comments.** A pin keeps its viewport (`viewportWidth`, `viewportHeight`, `deviceKey`) and shows at that width; the
  design reference and the console print `viewport <w>x<h> [<device>]`; `app:design-studio:comments --viewport
  --device` set it. **Schema:** three nullable columns on `DesignStudioComments`, no index:
  `viewportWidth` INT, `viewportHeight` INT, `deviceKey` VARCHAR(64) (take the exact DDL from the schema diff).
- **Thumbnails.** One per frame. The renderer request carries `"fullPage": true` for a frame: lay out at `width` ×
  `height`, photograph the whole page (height = page height, at least `height`); the response is unchanged (each
  image's `width` / `height` = the picture's size). A renderer without the field renders one screen. The example
  renderer supports it. The frame width is part of the thumbnail key; fixed boards keep their keys.
- **Route** (for the site view of the next release): `"route": "/path"` on a board or
  `<meta name="design-route" content="/path">`.

## 0.3.2

- **Fix: a render request no longer stays `RUNNING` without a run.** A board whose file or asset files changed between
  the listing and its render was skipped; when every board of a shard was skipped, no shard closed the request and the
  canvas showed "being rendered" until someone rendered by hand. Now a shard reads its changed boards again in a
  follow-up run 10 seconds later (at most 10 in a row), and a shard that is through watches the boards of the other
  shards every minute: the request closes when nothing is missing, or when the missing boards stayed the same for 3
  minutes while no shard waits for the renderer. Opening a canvas replaces an open request without change for 20
  minutes (before, only a new request from the canvas did, which an open canvas does not file).
- `RenderDesignStudioThumbnailsMessage` carries the follow-up state (`changedBoardFollowUpCount`,
  `remainingBoardsFingerprint`, `remainingBoardsUnchangedSince`); messages queued by 0.3.1 run as before.
- **Canvas: "Present this section" starts at the selected board.** With a board selected, the Play button presents
  that board's section from that board on (← / → go on from there); without a selection, the section in view from its
  first board as before.

## 0.3.1

- **Fix: canvases with more than 50 boards.** The entity sets of statuses, comments, thumbnails and thumbnail render
  requests carried `QueryOptionsTrait`, so every read of them stopped at the default `top` of 50 rows: beyond the 50th
  row a board showed the default status, comments of the canvas were missing (canvas view, console, poll, decisions,
  retention) and thumbnails counted as missing. No route of the studio offers query options; the sets no longer carry
  the trait and are read whole.

## 0.3.0

- **Board images from `assets/`:** a board may reference image files of `<canvas folder>/assets/` (`assets/<path>` or
  `./assets/<path>` in `src`, `href`, `poster`, `srcset` or CSS `url(…)`) instead of embedding them as data URIs. Types
  webp, png, jpg, jpeg, gif, avif, svg; plain path segments without a leading dot, resolved inside `assets/`; at most
  `DesignStudio.content.assetMaximumBytes` (new key, default 5 MiB). The board file is not changed; on delivery the
  references point at the new content route `GET /assets/{projectKey}/{canvasKey}/{assetPath}` (token, file version
  `v`), and the thumbnail renderer gets the files as data URIs (`DesignBoardAssetsService`).
- **Asset versions:** `DesignDirectoryBoard::$assetsHash` (the version of the files a board references) is part of the
  board's `htmlUrl` and of its thumbnail key, so a replaced photo reloads the board and renders new thumbnails; boards
  without assets keep their keys and thumbnails. The board version stays the git blob hash of the board file.
- **Snapshots:** a new thread keeps the referenced files content-addressed with a manifest of the version, so an
  earlier commented version shows the images it had.
- **CSP:** `img-src` of board HTML also allows the application itself (`'self'` and the request's origin).
- An application file `config/app/DesignStudio/content.php` must name the new key `assetMaximumBytes` (config files are
  replaced as a whole); without it the default applies.

## 0.2.4

- **Peer ranges:** `@reduxjs/toolkit` `^2.6.0` (the oldest release with every RTK Query type the package uses),
  `react-redux` `^9.0.0` (not imported by the package; the application's store provider).

## 0.2.3

- **Peer range:** `lucide-react` `>=0.550.0 <2` (the caret range of 0.2.2 held 0.x at its minor and excluded 0.552).

## 0.2.2

- **Peer ranges:** `react` / `react-dom` `^19.0.0`, `lucide-react` `^0.550.0 || ^1.0.0` (every icon the package uses
  exists there).
- **Docs:** the access policy / theme subclasses must be public services themselves, not only aliased (else the
  console commands fail with "removed or inlined"); the "Blocked script execution" console line comes from browser
  extensions, not from the board; pnpm `minimumReleaseAge` and `minimumReleaseAgeExclude`; the Next.js App Router
  example now carries a complete merged search-parameter writer, `useUrlState` (URL + sessionStorage) and
  `useNavigate`.

## 0.2.1

- **Code links:** a code link opens the side panel before the first fit (opening it later narrowed the canvas and
  re-packed the layout, so the board was out of view); a code link while the panel is closed fits after the re-layout.

## 0.2.0

- **Board codes:** a project and a canvas may set `code` in `DesignStudio.projects`; the boards of such a canvas get a
  short code `<project code> <canvas code>-<board part>` (`APP ONB-W2`), the board part from `code` in `canvas.json` or
  derived from the file name (`DesignBoardCodesService`; duplicates lose their code with a warning). New fields:
  `DesignStudioBoardView.code`, `DesignStudioCanvasView.code`, `DesignStudioCanvasSummary.code`.
- **Code links:** `<canvas page>?code=APP-ONB-W2` (also `ONB-W2`, `W2`) moves the canvas to the board, selects it and
  shows its panel; `<overview page>?code=…` finds the canvas first. The board header and panel show the code, "Copy
  link" copies the code link, the canvas search finds boards by code, design references carry `code …`.
- **Frontend contract:** optional `router.useNavigate` (overview opens a code); texts `boardCodeNotFound`,
  `boardLinkCopied`, `copyBoardLink`; helpers `boardCodeMatches`, `findBoardForCode`, `codeBelongsToCanvas`,
  `buildBoardCodeLink`, `boardCodeUrlValue`, `normalizeBoardCode`, `CODE_PARAM`.
- **Console:** `app:design-studio:show` lists codes; `--board` takes a code, and a full code needs no project / canvas.

## 0.1.0

Initial release. Requires `mgamadeus/ddd` ^3.15.3 and `mgamadeus/ddd-argus` ^1.3.3.

- **Directory model:** projects and canvases from the config `DesignStudio.projects`; boards and sections read from
  `canvas.json` and the board files of the running checkout (`DesignDirectoryReaderService`); a board version is the
  git blob hash of the file content. Nothing of it is stored.
- **Comment threads** (`DesignStudioComment`, `DesignStudioCommentsService`): pin position, replies, resolve / reopen,
  decision flag, author kind `USER` / `AGENT`, a snapshot of the commented board version in object storage.
- **Board statuses** (`DesignStudioBoardStatus`, `DesignStudioBoardStatusesService`): status keys from the config
  `DesignStudio.statuses`, with the board version the status was set on.
- **Retention:** idle threads are deleted after `DesignStudio.retention.threadIdleDays`, decisions only with
  `decisionIdleDays`; runs when a canvas is opened (at most once a day) and through `app:design-studio:prune`.
- **Thumbnails (optional):** `DesignStudioThumbnail`, `DesignStudioThumbnailRenderRequest`, the Argus repo
  `ArgusDesignBoardRenderedThumbnails`, the Messenger pair `RenderDesignStudioThumbnailsMessage` /
  `RenderDesignStudioThumbnailsHandler`; sharded runs, backoff while the renderer is busy or unreachable.
- **Views:** `DesignStudioCanvasViewsService` (overview, canvas view, revision fingerprint).
- **Delivery:** `DesignBoardContentService` (content token, script removal, theme injection); sandboxed delivery with a
  strict Content Security Policy.
- **Extension points:** `DesignAccessPolicyService` (who may use the studio), `DesignThemeProviderService` (themes).
- **Admin API:** controller traits `DesignStudioCanvasesControllerTrait`, `DesignStudioCommentsControllerTrait`,
  `DesignStudioContentControllerTrait` with their DTOs.
- **Console commands:** `app:design-studio:show`, `comments`, `comments-poll`, `decisions`, `status`, `theme`,
  `thumbnails`, `prune`.
- **Config defaults:** `DesignStudio/api.php`, `content.php`, `projects.php`, `renderer.php`, `retention.php`,
  `statuses.php`, `storage.php`.
- **Frontend package** `@mgamadeus/ddd-design-studio` (npm, in `web/`, same version): overview, project, reviews and
  canvas screens, focus view, comment threads with pins, review statuses, design references; the application wires it
  through `DesignStudioProvider` (API, router, UI primitives, session, storage, replaceable texts). Two entries: `.`
  and `./canvas` (the only one that loads `@xyflow/react`).
- **Renderer example** in `renderer/`: a stateless thumbnail renderer with the HTTP contract of the module.
- Tests (`vendor/bin/phpunit`; `pnpm test` in `web/`), CI and a release workflow that publishes the frontend package
  on a version tag.
