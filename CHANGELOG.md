# Changelog

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
