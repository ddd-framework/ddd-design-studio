# mgamadeus/ddd-design-studio -- Design Studio Module

Design review studio for the `mgamadeus/ddd` framework -- shows the design boards of a checkout on a canvas, with
comment threads, review statuses, decisions and optional thumbnails.

**Package:** `mgamadeus/ddd-design-studio` (v0.1.x); frontend: npm `@mgamadeus/ddd-design-studio` in `web/` (same version)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^3.15), `mgamadeus/ddd-argus` (^1.3.3)

> **This module follows all DDD Core conventions.**
> For base patterns, see vendor/mgamadeus/ddd/AGENTS.md and skills in vendor/mgamadeus/ddd.
> For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

For installing and operating the module in an application, see `README.md` and the skill
`.claude/skills/ddd-module-design-studio-specialist/SKILL.md`.

## The rule of the module

The directory says what exists; the database stores only what people said.

- Projects, canvases, boards, sections and board versions are read from configuration and the file system on every
  call. They are `ValueObject`s and are never persisted.
- Review state (comments, statuses) and the render cache (thumbnails, render requests) are `Entity` rows. They name a
  board by `projectKey` / `canvasKey` / `boardKey` and a version by `contentHash`; there is no foreign key to a board.
- A board version is `DesignDirectoryReaderService::computeContentHash()`: the git blob hash of the file content.

Do not add tables for boards, sections or canvases, and do not write anything read from the directory to the database.

## Architecture

```
src/
+-- Domain/DesignStudio/
|   +-- Entities/
|   |   +-- Definitions/     [DesignProjectDefinition(s), DesignCanvasDefinition(s) -- config, ValueObject]
|   |   +-- Directory/       [DesignDirectoryCanvas, DesignDirectoryBoard(s), DesignDirectorySection(s) -- file system, ValueObject]
|   |   +-- Views/           [DesignStudioOverview, DesignStudioProjectSummary(ies), DesignStudioCanvasSummary(ies),
|   |   |                     DesignStudioCanvasView, DesignStudioBoardView(s) -- API output, ValueObject]
|   |   +-- Themes/          [DesignTheme(s) -- ValueObject]
|   |   +-- Comments/        [DesignStudioComment(s) -- Entity]
|   |   +-- BoardStatuses/   [DesignStudioBoardStatus(es) -- Entity]
|   |   +-- Thumbnails/      [DesignStudioThumbnail(s) -- Entity; DesignBoardRenderedThumbnails -- Argus-loaded ValueObject]
|   |       +-- RenderRequests/ [DesignStudioThumbnailRenderRequest(s) -- Entity]
|   +-- Repo/
|   |   +-- DB/              [DB* repos + DB*Model per entity; DesignStudioRightsTrait]
|   |   +-- Argus/Thumbnails/ArgusDesignBoardRenderedThumbnails.php
|   +-- Services/            [see below]
|   +-- MessageHandlers/     [RenderDesignStudioThumbnailsMessage + RenderDesignStudioThumbnailsHandler]
+-- Presentation/Api/Admin/DesignStudio/
|   +-- Traits/              [DesignStudioCanvasesControllerTrait, DesignStudioCommentsControllerTrait,
|   |                         DesignStudioContentControllerTrait, DesignStudioViewerTrait]
|   +-- Dtos/                [request and response DTOs of the traits]
+-- Symfony/Commands/DesignStudio/ [DesignStudioCommand (base) + app:design-studio:* commands]
+-- Modules/DesignStudio/DesignStudioModule.php
config/app/DesignStudio/     [api, content, projects, renderer, retention, statuses, storage]
tests/                       [bootstrap.php, Support/TestContainer.php, Services/*Test.php]
web/                         [npm package @mgamadeus/ddd-design-studio: the React frontend]
+-- src/                     [index.ts (main entry), canvas/index.ts (/canvas entry), api/, provider/, screens/, canvas/, utils/]
+-- scripts/                 [clean.mjs, check-package.mjs]
+-- test/consumer/           [tarball installed into a fresh project, strict type check]
+-- package.json, tsup.config.ts, tsconfig.json, pnpm-lock.yaml, README.md
renderer/                    [example thumbnail renderer: Node service, Dockerfile, README.md with the HTTP contract]
.github/workflows/           [ci.yml (phpunit + web checks), release.yml (publishes web/ to npm on tag v*)]
```

The module ships no controllers, only controller traits: base controller, route prefix, tag and logging belong to the
application.

## Entities

| Entity | Type | Persisted | Purpose |
|--------|------|-----------|---------|
| `DesignProjectDefinition`, `DesignCanvasDefinition` | ValueObject | no (config) | a declared project / canvas: folder, board and section patterns, reference theme |
| `DesignDirectoryCanvas`, `DesignDirectoryBoard`, `DesignDirectorySection` | ValueObject | no (file system) | a canvas as read from its folder, with warnings |
| `DesignStudioOverview`, `DesignStudioCanvasView`, `DesignStudioBoardView`, summaries | ValueObject | no | what the API answers: directory joined with review state and delivery URLs |
| `DesignTheme` | ValueObject | no | a look offered by the theme provider (`themeId`, `label`, `supportsDark`) |
| `DesignStudioComment` | Entity | `DesignStudioComments` | thread root or reply; pin `x` / `y`, `isResolved`, `isDecision`, `authorKind`, `snapshotObjectKey`, `lastActivityAt` |
| `DesignStudioBoardStatus` | Entity | `DesignStudioBoardStatuses` | status of one board (unique on project, canvas, board) with the version it was set on |
| `DesignStudioThumbnail` | Entity | `DesignStudioThumbnails` | PNG of a board version in a look (unique `thumbnailKey`); a cache, deletable at any time |
| `DesignStudioThumbnailRenderRequest` | Entity | `DesignStudioThumbnailRenderRequests` | order to render the missing thumbnails of a canvas in a look; `STATUS_*`, `attempts`, `lastError` |
| `DesignBoardRenderedThumbnails` | ValueObject | no (Argus, uncached) | one render call: HTML and theme in, PNG or failure out |

`DesignStudioComment`, `DesignStudioBoardStatus` and `DesignStudioThumbnailRenderRequest` use `ChangeHistoryTrait`;
`DesignStudioThumbnail` does not.

## Services

All in `DDD\Domain\DesignStudio\Services\` (public via `DesignStudioModule::getPublicServiceNamespaces()`).

| Service | Responsibility |
|---------|----------------|
| `DesignProjectDefinitionsService` | reads and validates `DesignStudio.projects` (`KEY_PATTERN`, folder rules); `getProjectDefinitions()`, `getProjectDefinition()`, `getCanvasDefinition()` |
| `DesignDirectoryReaderService` | reads a canvas folder: `readCanvas()`, `readBoardContent()`, `isBoardKeyOfCanvas()`, static `computeContentHash()`; per-folder hash cache validated by mtime and size |
| `DesignStudioCanvasViewsService` | joins directory and review state: `getOverview()`, `getCanvasView()`, `getRevision()`, `getCanvasDefinition()` / `getDirectoryBoard()` (both throw `NotFoundException`), `getContentBasePath()` |
| `DesignStudioCommentsService` (EntitiesService) | threads: `findForCanvas()`, `createThread()`, `reply()`, `changeComment()`, `resolveThread()`, `reopenThread()`, `keepThread()`, `deleteComment()`, `getCanvasFingerprint()`; retention: `findExpiringThreads()`, `pruneExpiredThreads()`, `pruneExpiredThreadsIfDue()` |
| `DesignStudioBoardStatusesService` (EntitiesService) | `getStatuses()`, `findForCanvas()`, `findForBoard()`, `setStatus()` |
| `DesignStudioThumbnailsService` (EntitiesService) | `isRendererEnabled()`, `getRendererVersion()`, `computeThumbnailKey()`, `findForBoards()`, `findBoardsWithoutThumbnail()`, `storeThumbnail()`, `getThumbnailContent()` |
| `DesignStudioThumbnailRenderRequestsService` (EntitiesService) | `requestRendering()`, `findLatestRequest()`, `processRequest()` (the Messenger job), sharding (`getShardOfBoard()`, `getRenderConcurrency()`, `dispatchAllShards()`, `dispatchRun()`), backoff and stall detection |
| `DesignStudioFilesService` | object storage: snapshots (`storeSnapshot()`, `readSnapshot()`, `deleteSnapshot()`, `getSnapshotObjectKey()`) and thumbnails (`storeThumbnail()`, `storeSmallThumbnail()`, `readThumbnail()`); answers `null` without a configured store |
| `DesignBoardContentService` | delivery: `createContentToken()`, `authenticateContentToken()`, `getBoardHtml()`, `getBoardHtmlForSandbox()`, static `removeScripts()` / `applyTheme()` |
| `DesignAccessPolicyService` | extension point: `canView()`, `canComment()`, `canSetStatus()`, `canDecide()`, `getAccountDisplayName()` |
| `DesignThemeProviderService` | extension point: `findThemes()`, `getTheme()`, `getThemeStyle()`; fixed helpers `getThemeStyleForReference()`, static `computeThemeHash()`; a reference is parsed by `DesignThemeReference::fromString()` |

### Flows worth knowing before changing code

- **Canvas view:** `getCanvasView()` first calls `pruneExpiredThreadsIfDue()`, resolves the theme (an unknown theme
  falls back to no theme), reads the directory, then joins statuses, open thread counts and thumbnails and builds the
  delivery URLs with a content token.
- **Revision:** `computeRevision()` hashes boards (key, hash, position, size, section), sections, the comment
  fingerprint (count, newest id, newest activity) and statuses. Anything a page should notice must change it.
- **Thread activity:** `lastActivityAt` lives on the root only. Every write path of a thread must move it (directly or
  through `keepThread()`).
- **Snapshots** are content-addressed (`<prefix>/snapshots/<contentHash>.html`): stored once, deleted in
  `deleteWithSnapshot()` only when no other row references the key.
- **Thumbnail key:** `sha256(contentHash:themeHash:rendererVersion)`. Nothing is invalidated; a changed input has no
  thumbnail yet.
- **Render run:** `processRequest()` is one shard of one request. It re-reads the directory, renders with one call in
  flight (`ArgusDesignBoardRenderedThumbnails::startRender()` / `finishRender()`), hands over to a new message after
  `RUN_BUDGET_SECONDS`, and never throws for a renderer failure (retryable: wait with backoff; otherwise: `lastError`).
- **Rights:** `DesignStudioRightsTrait::applyReadRightsQuery()` on all four DB repos denies every row to accounts that
  fail `DesignAccessPolicyService::canView()`. Finer rules are checked in the services (`requireMayComment()`,
  `setStatus()`). Code that must run without an account (prune, token authentication) deactivates rights restrictions
  and restores the snapshot in `finally`.

## Extension points

- **`DesignAccessPolicyService`** and **`DesignThemeProviderService`**: an application subclasses the service and
  registers the subclass under this module's class as the service id. Always resolve them through
  `DDDService::instance()->getService(<module class>::class)`, never with `new`, or the application's binding is
  bypassed.
- **Controller traits:** new routes go into a trait, not into a controller class. Every action of the canvases and
  comments traits starts with `requireDesignStudioViewer()` (`DesignStudioViewerTrait`); the content trait authenticates
  with `DesignBoardContentService::authenticateContentToken()` instead and answers 404 on every failure.
- **Config:** every default lives in `config/app/DesignStudio/*.php` and has a fallback constant in the service that
  reads it. Application files replace a module file as a whole, so a new key needs a fallback in code.
- **Console commands:** extend `DesignStudioCommand` (`addCanvasArguments()`, `authenticate()`, `getCanvasDefinition()`,
  `formatComment()`); name them `app:design-studio:<verb>`. They are auto-registered in a consuming application; an
  application class with the same command name wins.
- **Messenger handler:** `RenderDesignStudioThumbnailsHandler` is bound to the transport
  `render_design_studio_thumbnails`. It is not auto-registered in a consuming application (the README documents the
  `services.yaml` tag); renaming the transport is a breaking change for every application.

## Conventions

- **Never `private`.** Methods and properties are `protected` or `public`.
- **Services via the container:** `DDDService::instance()->getService(SomeService::class)`; never `new` a service.
- **Typed nullable entity properties stay uninitialised**; read them with `??`.
- **No abbreviated variable names** (`$queryBuilder`, not `$qb`).
- **Entity changes:** after changing entity properties, regenerate the `DB*Model` classes
  (`app:generate-doctrine-models-for-entities` in an application that has the module installed); never edit them by hand.
- **Public repository:** no names of consuming applications, companies, people, hosts or local paths in code, comments,
  tests or docs. Neutral examples only (project `app`, canvas `onboarding`, theme `brand:1`).
- **Security-relevant code** (path checks in `DesignProjectDefinitionsService` / `DesignDirectoryReaderService`, key
  checks in `DesignStudioFilesService`, the CSP in `DesignStudioContentControllerTrait`, the content token) changes only
  together with a test and the "Security" section of the README.
- **Docs follow code:** a changed config key, route, command option or default is updated in `README.md`,
  `CHANGELOG.md` and the specialist skill in the same change.

## Frontend package (`web/`)

- Self-contained React code that takes everything from the application through `DesignStudioProvider`
  (`web/src/provider/types.ts`, `DesignStudioEnvironment`). It imports only `react`, `react-dom`, `@xyflow/react`,
  `lucide-react`, `@reduxjs/toolkit`, `react-redux`, `@mgamadeus/ddd-web/*`, `clsx`, `tailwind-merge` and relative
  files. No framework router, no application alias.
- Two entries: `src/index.ts` must never reach `@xyflow/react`; only `src/canvas/index.ts` does.
  `scripts/check-package.mjs` (part of `pnpm build`) fails the build otherwise.
- `web/src/api/types.ts` is the hand-maintained contract with the controller traits and DTOs in `src/Presentation`:
  a changed route, parameter or response property changes both sides in the same change.
- UI texts are English. A text an application may want to replace goes into `DesignStudioTexts`
  (`provider/types.ts`) with its default in `provider/texts.ts`, not into a component as a literal.
- Class names are Tailwind utilities the application's Tailwind scans from `dist`; do not minify the build.
- Checks: `pnpm typecheck`, `pnpm test` (node:test, `src/**/*.test.ts`), `pnpm build`, then
  `test/consumer/run.sh <tarball> <npm|pnpm> <workDir>` on the packed tarball.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite runs without an application kernel and without a database. `tests/bootstrap.php` defines `APP_ROOT_DIR`,
installs `DDD\Tests\DesignStudio\Support\TestContainer` as the bundle's default container (it resolves a service by
instantiating the requested class) and registers `config/app` as a module config directory, so
`Config::get('DesignStudio.…')` answers with the module defaults.

Follow the existing tests: subclass the service under test and override its protected seams (for example
`DesignDirectoryReaderService::getProjectRootDirectory()`, `DesignProjectDefinitionsService::getProjectsConfig()`,
the hash cache methods) instead of booting framework infrastructure. Code that needs the database is not covered by
this suite.

## Releases

The version in `composer.json` is the release: the git tag is `v<version>` (for example `0.1.0` and `v0.1.0`). Add the
`CHANGELOG.md` entry and bump `metadata.version` of the specialist skill and `version` in `web/package.json` in the
same change (CI and the release workflow fail when the two versions differ). The tag publishes the frontend package to
npm (`.github/workflows/release.yml`, trusted publishing). Release order and
dependency floors across modules: the `ddd-module-orchestrator` and `ddd-composer-update-version` skills in
`vendor/mgamadeus/ddd`.
