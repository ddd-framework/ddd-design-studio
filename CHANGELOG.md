# Changelog

## 0.1.0

Initial release. Requires `mgamadeus/ddd` ^3.15 and `mgamadeus/ddd-argus` ^1.3.3.

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
