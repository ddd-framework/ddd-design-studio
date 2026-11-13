# mgamadeus/ddd-design-studio

Design review studio for applications built on the [mgamadeus/ddd](https://github.com/ddd-framework/ddd) framework: it
shows the design boards of a checkout on a canvas, with comment threads, review statuses, a decisions log and optional
thumbnails.

## What it does

A design board is a self-contained HTML file. A canvas is a folder of the running checkout that holds the board files
and a `canvas.json` with their positions, sizes, titles and section notes. The studio reads these folders on every
request and joins them with the review state people (and agents) left in the database:

- **Canvas view** — sections and boards with position, size, version, review status, open thread count and delivery
  URLs for a sandboxed `<iframe>`.
- **Comment threads** — on a board or pinned to a point of it, with replies, resolve / reopen, a decision flag and an
  author kind (`USER` or `AGENT`). Each thread keeps a snapshot of the board version it was written on.
- **Board statuses** — one configurable status per board, with a hint when the board changed after the status was set.
- **Thumbnails (optional)** — PNGs rendered by an HTTP renderer service through an Argus repo and a Messenger handler.
- **Retention** — idle threads are deleted after a configurable time; decisions are kept by default.
- **Console commands** — `app:design-studio:*`, the way agents and developers work with the studio from a checkout.

## The model: directory versus database

The directory says what exists; the database stores only what people said.

| In the checkout (never stored) | In the database |
|---|---|
| Projects and canvases (config `DesignStudio.projects`) | Comment threads (`DesignStudioComments`) |
| Boards, their position, size, title (`canvas.json` + board files) | Board statuses (`DesignStudioBoardStatuses`) |
| Sections (title notes of `canvas.json`) | Thumbnails (`DesignStudioThumbnails`), a cache of render output |
| Board versions (computed from the file content) | Thumbnail render requests (`DesignStudioThumbnailRenderRequests`) |

Review state names its board by keys: project key, canvas key and board key (the file name). Several checkouts that
share one database therefore never overwrite or delete each other's boards.

A board version is the git blob hash of the file content, computed in PHP without a `.git` directory
(`DesignDirectoryReaderService::computeContentHash()`). It equals the output of:

```bash
git hash-object path/to/board.html
```

Comments, statuses and thumbnails store that hash. A board whose file has another hash today is shown as changed since
the comment or the status.

Two kinds of files are kept in object storage: the snapshot of a commented board version (content-addressed by its
hash, written once, shared by every thread on that version) and rendered thumbnails.

## Requirements

- PHP as required by `mgamadeus/ddd` ^3.15
- `mgamadeus/ddd` ^3.15.3 and `mgamadeus/ddd-argus` ^1.3.3 (installed as dependencies)
- A named store of the framework's object storage for snapshots and thumbnails (optional; see
  [Object storage](#7-object-storage))
- Imagick (optional): only for the reduced copies of a thumbnail (120 px PNG, 640 px WebP / JPEG preview); without it
  the full picture is delivered instead
- For thumbnails: a renderer HTTP service and a Messenger worker (optional)

## Installation

### 1. Require the package

```bash
composer require mgamadeus/ddd-design-studio
```

The module registers itself through the `ddd-module` key of its `composer.json`
(`DDD\Modules\DesignStudio\DesignStudioModule`): its services in `DDD\Domain\DesignStudio\Services\` are public
container services, and its `config/app` directory is registered as the module default (application files win).

The console commands extend Symfony's `Command` and are registered automatically (`mgamadeus/ddd` >= 3.14.3); no
`services.yaml` line is needed. An application class with the same command name wins.

### 2. Create the tables

Create the four tables through the framework's schema diff (see the `ddd-database-schema-diff-specialist` skill in
`vendor/mgamadeus/ddd`):

- `DesignStudioComments`
- `DesignStudioBoardStatuses`
- `DesignStudioThumbnails`
- `DesignStudioThumbnailRenderRequests`

### 3. Declare projects and canvases

Create `config/app/DesignStudio/projects.php` in the application. The module default declares no projects.

```php
<?php

declare(strict_types=1);

return [
    [
        'key' => 'app',                        // project key (URL segment)
        'label' => 'App',
        'path' => 'app',
        'canvases' => [
            [
                'key' => 'onboarding',         // canvas key (URL segment)
                'label' => 'Onboarding',
                'folder' => 'design/onboarding',   // relative to the project root
                'boards' => ['*'],             // file-name patterns of the boards on this canvas
                'sections' => ['*'],           // key patterns of the notes that are section titles
                'referenceTheme' => null,      // `<themeId>[:<mode>]` the canvas is designed in, or null
                'scripts' => false,            // true: the boards' own scripts run (Board scripts)
            ],
        ],
    ],
];
```

Config files are replaced as a whole, not merged (application over module over core). An application file names every
key it wants to keep.

### 4. Ship the canvas folders in the image

The studio reads `canvas.json` and the board files from the file system of the process that answers the request (and of
the worker that renders thumbnails). The canvas folders must be part of the deployed image or checkout. A canvas whose
folder or `canvas.json` is missing is shown empty with the warning `No <folder>/canvas.json in this checkout`.

### 5. Add thin controllers

The routes are defined in three controller traits. The application provides controllers on its own admin base
controller that `use` them; base controller, route prefix, tag and request logging are the application's.

| Trait | Routes |
|---|---|
| `DDD\Presentation\Api\Admin\DesignStudio\Traits\DesignStudioCanvasesControllerTrait` | overview, themes, canvas, revision, thumbnail render request, board status |
| `DDD\Presentation\Api\Admin\DesignStudio\Traits\DesignStudioCommentsControllerTrait` | comment threads |
| `DDD\Presentation\Api\Admin\DesignStudio\Traits\DesignStudioContentControllerTrait` | delivery of board files and thumbnails |

```php
<?php

declare(strict_types=1);

namespace App\Presentation\Api\Admin\DesignStudio\Controller;

use App\Presentation\Api\Admin\Base\AdminController;   // the application's admin base controller
use DDD\Presentation\Api\Admin\DesignStudio\Traits\DesignStudioCanvasesControllerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Route;

#[Route('/designStudio')]
#[Tag(group: 'DesignStudio', name: 'Canvases', description: 'Design studio canvases, statuses and themes')]
class DesignStudioCanvasesController extends AdminController
{
    use DesignStudioCanvasesControllerTrait;
}
```

```php
#[Route('/designStudio')]
#[Tag(group: 'DesignStudio', name: 'Comments', description: 'Design studio comment threads')]
class DesignStudioCommentsController extends AdminController
{
    use DesignStudioCommentsControllerTrait;
}
```

```php
#[Route('/designStudio/content')]
class DesignStudioContentController extends AdminController
{
    use DesignStudioContentControllerTrait;
}
```

The content controller must be reachable at the path of the config `DesignStudio.api.contentBasePath` (default
`/api/admin/designStudio/content`): the studio builds the delivery URLs of boards and thumbnails from that value. With
admin controllers mounted at `/api/admin`, the example above matches the default. If the application mounts it
elsewhere, override `config/app/DesignStudio/api.php`. Hide the content controller from the API documentation; its
routes are loaded by `<iframe src>` and `<img src>`, not by an API client.

### 6. Let the content routes through the firewall

Board files and thumbnails are loaded by `<iframe src>` / `<img src>`, which cannot send an `Authorization` header.
Access to these two routes comes only from the content token in the URL (see [Security](#security)). Make the content
path public in `security.yaml`, above the rule for the admin API:

```yaml
security:
    access_control:
        - { path: ^/api/admin/designStudio/content, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin, roles: ROLE_ADMIN }
```

### 7. Object storage

Snapshots and thumbnails go to the object store named in `config/app/DesignStudio/storage.php` (default store name
`design-studio`). Configure that store in the framework's object storage, either in the application's
`config/app/Common/ObjectStorage.php` or through `OBJECT_STORAGE_DESIGN_STUDIO_*` environment variables (credentials
come from the environment only):

```php
// config/app/Common/ObjectStorage.php
return [
    'stores' => [
        'design-studio' => ['driver' => 's3', 'endpoint' => 'https://objects.example.com', 'bucket' => 'design-studio'],
    ],
];
```

```env
OBJECT_STORAGE_DESIGN_STUDIO_ACCESS_KEY=...
OBJECT_STORAGE_DESIGN_STUDIO_SECRET_KEY=...
```

Without a configured store nothing is kept: a comment is stored without a snapshot (the commented version cannot be
shown once the board changed), and thumbnails cannot be stored.

### 8. Optional: thumbnails (renderer, Messenger, worker)

Without a renderer the studio delivers no thumbnails and files no render requests; no worker is needed. To switch
thumbnails on:

**a) Configure the renderer** in the application's `config/app/DesignStudio/renderer.php` (copy the module file, keep
every key, set `enabled` to `true`). The module calls the endpoint through its Argus repo
`ArgusDesignBoardRenderedThumbnails`: a path is relative to the Argus base URL, an absolute URL is used as it is. The
token is read from the environment variable named in `tokenEnv` and sent as `X-Render-Token`; the application's own
bearer token is never sent to the renderer.

The module posts JSON `{ "html", "width", "height", "themes": [{ "themeHash", "css" }] }` and reads `images[]` from the
answer, each with `themeHash` and a base64 PNG in `full`.

**b) Register the Messenger handler.** It is not registered automatically. Add to `services.yaml`:

```yaml
DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsHandler:
    tags:
        - { name: messenger.message_handler, from_transport: render_design_studio_thumbnails }
    bind:
        Psr\Log\LoggerInterface $messengerLogger: '@monolog.logger.messenger'
```

**c) Add the transport and the routing** to `messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            render_design_studio_thumbnails: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/render_design_studio_thumbnails'
        routing:
            'DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsMessage': render_design_studio_thumbnails
```

The handler is bound to the transport name `render_design_studio_thumbnails`; the transport must carry that name.

**d) Run a worker:**

```bash
php bin/console messenger:consume render_design_studio_thumbnails
```

A render request runs in as many shards as the configured concurrency, one message each. Run at least that many
consumer processes, or the shards run one after another. The worker reads the boards from its own checkout, so it needs
the canvas folders too. Rebuild the container before (re)starting workers after shipping the handler registration.

### 9. Optional: access policy and themes

Two services are extension points. Subclass the service and register the subclass under the module class' service id:

```yaml
# services.yaml
DDD\Domain\DesignStudio\Services\DesignAccessPolicyService:
    class: App\Domain\DesignStudio\Services\AppDesignAccessPolicyService
    public: true

DDD\Domain\DesignStudio\Services\DesignThemeProviderService:
    class: App\Domain\DesignStudio\Services\AppDesignThemeProviderService
    public: true
```

**`DesignAccessPolicyService`** — who may use the studio. The default lets accounts with an admin role do everything
and everyone else nothing.

| Method | Decides | Default |
|---|---|---|
| `canView(?Account $account): bool` | see canvases, boards, comments, statuses | account has an admin role |
| `canComment(?Account $account): bool` | write, reply, resolve, reopen, delete comments | `canView()` |
| `canSetStatus(?Account $account): bool` | set a board status | `canView()` |
| `canDecide(?Account $account): bool` | set or clear the decision flag | `canView()` |
| `getAccountDisplayName(Account $account): string` | author name of comments and status changes | `Account <id>` |

**`DesignThemeProviderService`** — the looks boards can be shown in. A theme is a `<style data-design-theme>` element
that the studio places first in a board's `<head>`, so CSS variables with fallbacks in the board pick it up. The
default knows no themes: boards are shown as they are.

| Method | Returns | Default |
|---|---|---|
| `findThemes(?string $searchText = null): DesignThemes` | themes offered to a theme switcher | empty set |
| `getTheme(string $themeId): ?DesignTheme` | one theme | `null` |
| `getThemeStyle(string $themeId, string $mode): ?string` | the `<style>` element, `null` = unknown theme | `null` |

A theme reference is `<themeId>` or `<themeId>:<mode>` with mode `light` or `dark` (`light` when omitted), for example
`brand:1` or `brand:1:dark`. A `DesignTheme` has `themeId`, `label` and `supportsDark`.

## Configuration reference

All files live in `config/app/DesignStudio/`. An application file replaces the module file as a whole.

### `projects.php` — `DesignStudio.projects`

A list of projects. Default: `[]`.

| Key | Meaning | Default |
|---|---|---|
| `key` | project key; lower-case letters, digits and hyphens, starting with a letter or digit, at most 63 characters; unique | required |
| `label` | display name | the key |
| `path` | read into the project definition (`DesignProjectDefinition::$path`); not evaluated by the module's PHP code | the key |
| `canvases[].key` | canvas key, same rules, unique within the project | required |
| `canvases[].label` | display name | `title` of `canvas.json`, else the canvas key |
| `canvases[].folder` | folder relative to the project root; not absolute, no `..` segment | required |
| `canvases[].boards` | `fnmatch` patterns of board file names; empty = every board of `canvas.json` | `[]` |
| `canvases[].sections` | `fnmatch` patterns of note keys that are section titles; empty = every note whose `kind` starts with `title` | `[]` |
| `canvases[].referenceTheme` | theme reference used when a request names no theme | `null` |
| `canvases[].scripts` | `true`: the boards' own scripts run, sandboxed (see Board scripts) | `false` |

An invalid or duplicate key or an unsafe folder makes every read of the configuration fail with a `BadRequestException`.

### `api.php` — `DesignStudio.api`

| Key | Meaning | Default |
|---|---|---|
| `contentBasePath` | path of the content routes, without a trailing slash | `/api/admin/designStudio/content` |

### `content.php` — `DesignStudio.content`

| Key | Meaning | Default |
|---|---|---|
| `boardMaximumBytes` | largest board file read; a larger file is left out with a warning | `16 * 1024 * 1024` |
| `canvasJsonMaximumBytes` | largest `canvas.json` read; a larger file is refused | `4 * 1024 * 1024` |
| `assetMaximumBytes` | largest asset file (image under `<canvas folder>/assets/`) delivered; a larger file is not delivered | `5 * 1024 * 1024` |

### `statuses.php` — `DesignStudio.statuses`

A list of status keys in display order. The first is the status of a board without a stored one. Default:
`['DRAFT', 'IN_REVIEW', 'CHOSEN', 'REJECTED']`. Labels and colours belong to the frontend. A status key is stored in a
column of 32 characters.

### `retention.php` — `DesignStudio.retention`

| Key | Meaning | Default |
|---|---|---|
| `threadIdleDays` | a thread without activity is deleted after this many days | `180` |
| `expiryWarningDays` | threads expiring within this many days are listed as expiring | `14` |
| `decisionIdleDays` | idle days after which a decision thread is deleted; `null` = never | `null` |

### `storage.php` — `DesignStudio.storage`

| Key | Meaning | Default |
|---|---|---|
| `store` | name of the object store | `design-studio` |
| `prefix` | key prefix inside the store | `design-studio` |

Object keys: `<prefix>/snapshots/<contentHash>.html`, `<prefix>/thumbnails/<thumbnailKey>.png` and
`<prefix>/thumbnails/<thumbnailKey>-w120.png`, and a preview for middle zoom levels, at most 640 px wide, as
`<prefix>/thumbnails/<thumbnailKey>-w640.webp` (`.jpg` where Imagick has no WebP). The canvas loads the full PNG only
when a board is drawn wider than 800 px; a thumbnail stored before previews existed gets its preview on first request.

### `renderer.php` — `DesignStudio.renderer`

| Key | Meaning | Default |
|---|---|---|
| `enabled` | `false`: no thumbnails are requested, rendered or shown | `false` |
| `endpoint` | `POST:<absolute URL>` or `POST:<path>` relative to the Argus base URL | `POST:/designBoardRenderer/thumbnails` |
| `endpointEnv` | name of an environment variable with an absolute URL that replaces the endpoint; `null` = none | `DESIGN_BOARD_RENDERER_ENDPOINT` |
| `tokenEnv` | name of the environment variable with the caller token (`X-Render-Token`); `null` or empty variable = no header | `DESIGN_BOARD_RENDERER_TOKEN` |
| `concurrency` | boards rendered at once = shards of a render request (1 to 8) | `2` |
| `concurrencyEnv` | name of an environment variable that overrides the concurrency; `null` = none | `DESIGN_RENDER_CONCURRENCY` |
| `rendererVersion` | part of every thumbnail key; change it when the renderer's output changes and every thumbnail is rendered anew | `playwright-1.64.1` |

### `devices.php` — `DesignStudio.devices`

| Key | Meaning | Default |
|---|---|---|
| `devices` | device presets of responsive boards, in picker order: `key` (`a-z`, digits, `-`), `label`, `width` and `height` in CSS pixels (width 240 to 3840), `kind` `DESKTOP` / `TABLET` / `PHONE` | Desktop 1920×1080 and 1440×900, Laptop 1280×800, iPad Pro 12.9" landscape / portrait, iPad 10.9" landscape / portrait, iPhone 16 Pro Max, iPhone 16 / 15, iPhone SE, Android |
| `defaultFrameDeviceKeys` | frames of a responsive board when neither the board nor the canvas lists its own `devices` | `desktop-1440`, `ipad-10-portrait`, `iphone-16` |

## Canvas folder format

A canvas folder holds `canvas.json` and one HTML file per board.

```json
{
    "title": "Onboarding",
    "boards": {
        "welcome.html": { "x": 100, "y": 1100, "w": 390, "h": 844, "title": "Welcome" },
        "sign-in.html": { "x": 600, "y": 1100, "title": "Sign in" }
    },
    "notes": {
        "title-start": { "x": 100, "y": 1000, "text": "Start", "maxW": 900, "kind": "title" }
    }
}
```

How `DesignDirectoryReaderService` reads it:

- `boards` is required and must be an object; otherwise the read fails with a `BadRequestException`, as it does for
  invalid JSON or an oversized file. `title` is used as the canvas name when the configuration has no `label`.
- **Board key** = the key in `boards` = the file name in the canvas folder. It must be a plain file name (no path), must
  not start with a dot, must end with `.html` and must match one of the canvas' `boards` patterns (when there are any).
  Other entries are skipped silently.
- **Board fields:** `x`, `y` (default `0`), `w` (default `390`), `h` (default `844`), `title` (default: the board
  key). All numbers are read as integers.
- **Responsive boards.** `"responsive": true` on a board, or on the canvas for all its boards, or
  `<meta name="design-responsive">` in the board file marks a layout that adapts to the viewport width. `"devices":
  ["desktop-1440", "iphone-16"]` on the board, else on the canvas, else `defaultFrameDeviceKeys` names its frames
  (unknown keys are left out). `w` / `h` do not apply to a responsive board. Boards without the marker stay fixed, as
  before.
- **Route.** `"route": "/pricing"` on a board, or `<meta name="design-route" content="/pricing">` in its file, names its
  page in the site view (`/` = the start page). A route is a path without query or `..`; anything else is ignored.
- A board whose file is missing or larger than `boardMaximumBytes` is left out and reported in the canvas' `warnings`.
- **Sections** come from `notes`. With `sections` patterns in the configuration, a note is a section when its key
  matches one; without patterns, when its `kind` starts with `title`. Note fields: `x`, `y` (default `0`), `text`
  (the title; default: the note key), `maxW` (default `0` = unbounded).
- A board belongs to the lowest section title that is not below it (`section.y <= board.y`); the horizontal position
  plays no part. Section titles without a board are left out. A board above every section title has no section.
- Boards are ordered top to bottom, then left to right; sections top to bottom. `displayOrder` starts at 1.
- Positions are shifted so that the smallest `x` and the smallest `y` of the selected boards and sections are `0`.
- Content hashes are cached per folder and validated against modification time and size of each file, so an unchanged
  canvas is read without opening its board files.

### Responsive boards

A responsive board is one file shown at several viewport widths:

- **Canvas.** One frame per frame device, side by side, each with the device name and width above it, the same status,
  code and comments. The toolbar switches all frames between one screen (the device height) and the whole page (the
  height the renderer measured at that width; at most 8000 px on the canvas).
- **Focus view and present mode.** A device picker (the presets), landscape / portrait, a free width by the drag handle
  at the right edge and the device frame switch. A viewport wider than the screen is scaled down. The board runs as
  on the device: one screen high, the page scrolls inside it (fixed and sticky elements, `vh` units as on the device;
  the board route's `site=1` script reports the scroll position, pins and the marked region follow it). The chosen viewport
  stays while stepping through boards (also in present mode) and is reset when the focus view closes.
- **Comments.** A pin keeps the viewport it was placed in (`viewportWidth`, `viewportHeight`, `deviceKey` on the
  comment); `x` / `y` are positions in the page at that width. A pin is shown at its width only; the thread names its
  viewport and opens it. The design reference carries `viewport <w>x<h> [<device>]`, the console prints it after the
  position, and `app:design-studio:comments … --new=… --x --y --viewport=393x852 --device=iphone-16` sets it.
- **Thumbnails.** One picture per frame: the renderer gets the device width and height with `fullPage: true` and
  photographs the whole page. The frame width is part of the thumbnail key; a fixed board keeps its key. The
  renderer contract: [`renderer/README.md`](renderer/README.md).

### Site view: a canvas as a clickable website

The boards with a route are the pages of a website; `DesignStudioSiteScreen` (npm package, route `site(…)` of the
application's paths) shows one page at a time, full width in a chosen device (the device picker, rotation, free width
and device frame of the focus view), with its own URL per page (`…/site/pricing`: shareable, browser back / forward).

- **Links.** A click on `href="/pricing"`, a relative `href="pricing"`, or `href="board:pricing.html"` opens that
  board's page; `href="#faq"` scrolls the page; a link without a page shows "No page for /x"; external links
  (`https:`, `mailto:`, `//…`) are not followed. Forms are not submitted.
- **How.** The page is the board route with `site=1`: the board's own scripts removed (a canvas with board scripts
  keeps them), the studio's navigation script added, admitted by its hash in the CSP (`script-src 'sha256-…'`,
  `sandbox allow-scripts`, no same origin): it reports clicked links, the scroll position and the page height to the
  studio page by `postMessage`; inline handlers and `javascript:` URLs stay blocked. The page keeps the device's
  viewport height and scrolls itself.
- **Comments.** Pins are placed and shown on the page as on the canvas (page positions in the viewport they were
  placed in, the same threads); they move with the page's scroll position.
- **Routes.** `"route"` in canvas.json or `<meta name="design-route">`, one board per route (a second board with the
  same route loses it, with a warning). `/` is the start page; without it the site opens at the first page.

### Board scripts

A canvas with `'scripts' => true` in `DesignStudio.projects` keeps its boards' own scripts: timed sequences, an
`IntersectionObserver`, a small state machine for an app tour. Every other canvas is unchanged: its `<script>` elements
are removed before delivery.

```html
<script>
  setInterval(() => document.querySelector('.tour').classList.toggle('step-2'), 1000);
</script>
<script src="assets/tour.js"></script>   <!-- a script file of the canvas' assets/ folder -->
```

- **Where they run.** Canvas tiles and device frames, the focus view and present mode, the site view (next to the
  navigation script: links, scroll position and height are reported as before), and the thumbnails.
- **What the CSP allows.** `script-src 'unsafe-inline' <origin><contentBasePath>/assets/<project>/<canvas>/`: inline
  `<script>` elements (inline handlers and `javascript:` URLs too) and the canvas' own script files, nothing else;
  `connect-src 'none'`, `form-action 'none'`, `default-src 'none'`, no external host. The iframe and the CSP carry
  `sandbox allow-scripts`, never `allow-same-origin`: the board runs in an opaque origin without access to the studio
  page, its cookies, its storage or its token.
- **Script files.** `assets/<path>.js` is an asset like an image (same path rules, same `assetsHash`: a new version of
  the file reloads the board and renders its thumbnails anew). Use classic scripts (`<script src>`); a `type="module"`
  script file is fetched with CORS and refused in the opaque origin (inline module scripts work, imports between files
  do not).
- **What does not work.** No network (`fetch`, XHR, WebSocket, external scripts); no storage that survives a reload
  (`localStorage` and cookies throw or are empty in the opaque origin); no forms; no navigation of the studio page.
- **Thumbnails.** The studio sends `"scripts": true` to the renderer (the canvas' script files inlined as `data:` URLs);
  renderer 1.1.0 runs the scripts and takes the picture 300 ms after the load. An older renderer removes them: the
  thumbnail shows the board before its scripts ran. Thumbnails of a canvas with board scripts have keys of their own.

### Images: the `assets/` folder

A board can load images from `<canvas folder>/assets/` instead of embedding them as data URIs, so a photo used by many
boards is one file in the repository:

```html
<img src="assets/photos/start-hero-m.webp" alt="">
<img srcset="assets/photos/hero.webp 1x, assets/photos/hero@2x.webp 2x">
<div style="background-image: url('./assets/patterns/dots.svg')"></div>
```

- A reference is `assets/<path>` or `./assets/<path>`, relative to the canvas folder, in a `src`, `href`, `poster` or
  `srcset` attribute or in a CSS `url(…)` (inline style or `<style>`). A browser that opens the board file from disk
  resolves it by itself; the studio rewrites it on delivery (`DesignBoardAssetsService`).
- File types: `webp`, `png`, `jpg` / `jpeg`, `gif`, `avif`, `svg`, and `js` (runs only on a canvas with board
  scripts). Path segments consist of letters, digits, `.`, `_`
  and `-`, and none starts with a dot (no `..`, no dotfiles). The file must resolve (symbolic links included) to a
  place inside `assets/` and be at most `assetMaximumBytes`. Anything else stays unresolved, and the image is missing.
- **Versions.** The board version stays the git blob hash of the board file. The asset files a board references have a
  version of their own, the board's `assetsHash`: it is part of the board's `htmlUrl` and of its thumbnail key, so
  replacing a photo reloads the boards that show it and renders their thumbnails again. Boards without assets keep the
  thumbnails they had.
- **Delivery.** In the iframe, each reference points at the asset route with the content token and the file's version
  (`…/assets/<project>/<canvas>/<path>?token=…&v=<blob hash>`). The thumbnail renderer gets the files embedded as data
  URIs; it still loads nothing from the network.
- **Commented versions.** When a thread starts, the referenced files are kept in the object store, content-addressed by
  their blob hash, with a manifest of the board version's paths. An earlier version is then shown with the images it
  had; a file the snapshot did not keep (no store configured, or the thread is older than this feature) is shown as it
  is in the checkout. Deleting the last thread of a version deletes its manifest; the files stay, as other versions
  may show them.

## API routes

Paths are relative to the route prefix of the application's controllers.

### `DesignStudioCanvasesControllerTrait`

| Verb | Path | Purpose |
|---|---|---|
| GET | `/overview` | projects with canvases (board count, open threads, decisions), status keys, renderer and theme flags, retention numbers |
| GET | `/themes` | themes of the theme provider; query `search` |
| GET | `/canvases/{projectKey}/{canvasKey}` | the canvas view; query `theme` (default: the canvas' reference theme). Deletes expired threads when that has not happened within the last day |
| GET | `/canvases/{projectKey}/{canvasKey}/revision` | fingerprint of board files, positions, sections, comments and statuses, for polling |
| POST | `/canvases/{projectKey}/{canvasKey}/thumbnailRenderRequests` | files or refreshes the render request for the missing thumbnails (body `theme`, `priorityBoardKeys`) and answers the canvas view; without a renderer nothing is filed |
| PATCH | `/canvases/{projectKey}/{canvasKey}/boards/{boardKey}/status` | sets the board status (body `status`) and stores the board version it was set on |

### `DesignStudioCommentsControllerTrait`

| Verb | Path | Purpose |
|---|---|---|
| GET | `/canvases/{projectKey}/{canvasKey}/comments` | thread roots and replies of the canvas, oldest first; query `onlyOpenThreads`, `sinceId`. Includes threads on boards that are not in the checkout |
| GET | `/comments/expiring` | thread roots that expire within the warning time |
| POST | `/canvases/{projectKey}/{canvasKey}/boards/{boardKey}/comments` | starts a thread (body `text`, optional `x`, `y`, `isDecision`) and keeps a snapshot of the board version |
| POST | `/comments/{designStudioCommentId}/replies` | adds a reply (body `text`) |
| PATCH | `/comments/{designStudioCommentId}` | changes the text (author only) and / or the decision flag |
| POST | `/comments/{designStudioCommentId}/resolve` | resolves the thread, optionally after a last reply (body `replyText`) |
| POST | `/comments/{designStudioCommentId}/reopen` | reopens the thread |
| POST | `/comments/{designStudioCommentId}/keep` | counts as activity: the thread's expiry starts again |
| DELETE | `/comments/{designStudioCommentId}` | deletes a comment; a thread root with its replies |

Comments written through the API have the author kind `USER`. A comment text has at most 10,000 characters.

### `DesignStudioContentControllerTrait`

| Verb | Path | Purpose |
|---|---|---|
| GET | `/boards/{projectKey}/{canvasKey}/{boardKey}` | the board HTML for the sandboxed iframe; query `token` (required), `contentHash`, `assetsHash`, `theme`, `site=1` (the site view: with the navigation script), `scripts=1` (set by the canvas view for a board whose scripts run; keeps cached copies apart) |
| GET | `/thumbnails/{thumbnailKey}` | a thumbnail; query `token` (required), `size` (`full` PNG, `small` PNG, `preview` WebP / JPEG) |
| GET | `/assets/{projectKey}/{canvasKey}/{assetPath}` | an image of the canvas' `assets/` folder; query `token` (required), `v` (the file's blob hash: the checkout's file when it matches, else the copy kept with a commented version) |

The board HTML query also takes `assetsHash` (set in `htmlUrl`). The canvas view carries ready-made URLs for both (`htmlUrl`, `thumbnailUrl`, `thumbnailSmallUrl`), including the token.

## Console commands

All commands run as the framework's account for CLI operations (`CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS`). That
account must pass the access policy. Boards are the files of the checkout the command runs in.

| Command | Arguments | Options | Purpose |
|---|---|---|---|
| `app:design-studio:show` | `[project] [canvas]` | `--board=<file>`, `--open` | without both arguments: the overview; with them: the canvas by section (board, version, status, open threads, warnings); `--board`: one board with its threads; `--open`: only boards with open threads |
| `app:design-studio:comments` | `project canvas` | list: `--open`, `--board=<file>`, `--sinceId=<id>`; new thread: `--new=<text> --board=<file> [--x --y] [--viewport=<w>x<h> [--device=<key>]] [--decision]`; actions: `--reply=<id> --text=<text>`, `--resolve=<id> [--text=<text>]`, `--reopen=<id>`, `--keep=<id>`, `--delete=<id>`; `--as=<name>` (default `Agent`) | lists threads grouped by board, or performs one action. Comments are written with author kind `AGENT` |
| `app:design-studio:comments-poll` | `project canvas` | `--sinceId=<id>` (default `0`), `--wait=<seconds>` (default `0`) | prints comments newer than the id, optionally waiting for one (looks every 5 seconds), then `sinceId=<newest id>` to continue from |
| `app:design-studio:decisions` | `project canvas` | — | the decisions log (decision threads with their replies) as Markdown |
| `app:design-studio:status` | `project canvas board status` | `--as=<name>` (default `Agent`) | sets the review status of a board |
| `app:design-studio:theme` | `[theme]` | `--search=<text>` | without argument: lists the themes; with a theme reference: prints its `<style>` element |
| `app:design-studio:thumbnails` | `project canvas` | `--theme=<reference>`, `--render` | reports how many pictures (fixed boards and device frames of responsive boards) have a thumbnail in the look and the newest render request; `--render` files a request for the missing ones |
| `app:design-studio:prune` | — | `--dryRun` | deletes expired threads (or reports them) and lists the ones expiring soon |

Examples:

```bash
php bin/console app:design-studio:show app onboarding
php bin/console app:design-studio:comments app onboarding --open
php bin/console app:design-studio:comments app onboarding --new="Spacing above the button" --board=welcome.html --x=120 --y=640
php bin/console app:design-studio:comments app onboarding --resolve=42 --text="done" --as="Design agent"
php bin/console app:design-studio:status app onboarding welcome.html IN_REVIEW
```

## Review state and retention

**Threads.** A thread root names project, canvas, board and the content hash of the board version; replies point to
their root. Resolving, reopening and the decision flag live on the root. Deleting a root deletes its replies (database
cascade) and its snapshot when no other thread uses it. Only the author's account may change a comment's text.

**Statuses.** One row per board that has a status other than the default. A stored status that is no longer in the
configured list is shown as the default status. The canvas view reports `hasChangedSinceStatus` when the board file has
another hash than at the time the status was set.

**Activity and expiry.** `lastActivityAt` on the thread root is the clock. Starting a thread, a reply, a text or
decision change, resolve, reopen and keep set it. A thread idle longer than `threadIdleDays` is deleted. A decision
thread is never deleted while `decisionIdleDays` is `null`; with a number it is deleted after that many idle days.

**When expiry runs.** Opening a canvas (the canvas view) deletes expired threads when that has not happened within the
last 24 hours (a cache marker), so no scheduled job is needed. `app:design-studio:prune` does the same on demand. The
prune runs without account rights.

**Thumbnails.** A thumbnail belongs to a board version in a look: its key is the SHA-256 of content hash, theme hash and
renderer version. A changed board, changed theme CSS or a new renderer version simply has no thumbnail yet; nothing is
invalidated. Thumbnail rows can be deleted at any time and are rendered again on demand.

**Render requests.** At most one open request per canvas and theme hash; a second request only refreshes the priority
boards. A run renders the boards of its shard (priority boards first) for at most 90 seconds, then continues in a new
message. Renderer answers 401, 404, 429, 502 to 504 and transport failures make the request wait
(`WAITING_FOR_RENDERER`) and retry with exponential backoff (30 seconds doubling up to 10 minutes, 30 attempts, then
`FAILED`). Another failure concerns one board: it is recorded in `lastError` and the other boards are still rendered.
A board whose file or asset files change between the listing and its render (boards regenerated during a run) is read
again in a follow-up run of its shard 10 seconds later. A shard that is through looks at the other shards' boards every
minute; the request closes when nothing is missing, or when the missing boards stayed the same for 3 minutes while no
shard waits for the renderer (`lastError` then says how many could not be rendered). An open request without any change
for 20 minutes counts as lost: opening the canvas closes it as `FAILED` and files a new one.

## Security

- **Access.** Every API action asks `DesignAccessPolicyService::canView()` and answers 403 otherwise. The database
  repositories apply the same rule as read rights (which also gate writes), so an account that may not view the studio
  gets no row. Comment, status and decision rights are checked by the services.
- **Content token.** The two content routes are public at the firewall. They require the `token` query parameter: a JWT
  with the scope `designStudioContent` and the account id, created for the viewer of the canvas view. Issue time and
  expiry are quantised to half-hour windows (a token is valid for 30 to 60 minutes and is the same for every request of
  a window, so URLs stay stable and the browser cache works). The token's account is loaded and must still pass
  `canView()`. Every failure answers 404.
- **Sandboxed delivery.** Board files are untrusted HTML. `<script>` elements are removed before delivery (a canvas
  with board scripts keeps them: `script-src 'unsafe-inline'` plus its own asset path, `sandbox allow-scripts`, see
  Board scripts), and the answer carries a Content Security Policy: `default-src 'none'`, `script-src 'none'`, `connect-src 'none'`,
  `form-action 'none'`, `base-uri 'none'`, `frame-ancestors 'self'`, `sandbox`, styles inline and from
  `https://fonts.googleapis.com`, fonts from `https://fonts.gstatic.com` and `data:`, images as `data:` / `blob:` and from
  the application itself (`'self'` and the request's origin: the asset route). Thumbnails are sent with
  `default-src 'none'; sandbox`, asset files with `default-src 'none'; style-src 'unsafe-inline'; img-src data:;
  sandbox` (an SVG opened directly runs and loads nothing). All carry `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: no-referrer`; boards and thumbnails `Cross-Origin-Resource-Policy: same-origin`, asset files
  `cross-origin` (they are loaded by the sandboxed iframe, whose origin is opaque).
- **Caching.** A URL with a `contentHash` (for a board with assets: together with its current `assetsHash`), every
  thumbnail URL and an asset URL with `v` are answered with `Cache-Control: private, max-age=31536000, immutable`; other
  board and asset URLs with `private, no-cache`. Both carry
  an ETag.
- **Path restrictions.** Project and canvas keys are validated against a fixed pattern. A canvas folder must be
  relative, must not contain `..`, and must resolve (symbolic links included) to a place inside the project root. A
  board key must be a plain `.html` file name of the canvas. A requested version must be 40 hex characters; a snapshot
  is only delivered when its content hashes to the requested version. Snapshot and thumbnail object keys are checked
  against the module's own key shapes before the store is read.
- **Asset paths.** Only image types, plain segments without a leading dot, resolved inside the canvas' `assets/`
  folder; a requested `v` must be 40 hex characters, and a kept copy is only delivered when it hashes to it.
- **Size limits.** `boardMaximumBytes`, `canvasJsonMaximumBytes` and `assetMaximumBytes` (see [Configuration reference](#configuration-reference)).

## Frontend

The React frontend of the studio is the npm package **`@mgamadeus/ddd-design-studio`** in [`web/`](web/README.md):
overview, project and canvas screens, the focus view, comment threads and review statuses, built on RTK Query and
`@mgamadeus/ddd-web`. It carries the same version number as this Composer package.

```bash
npm install @mgamadeus/ddd-design-studio
```

The application hands it its API client, router, UI primitives, session and texts through one provider and creates the
pages; the API prefix of the controllers from [step 5](#5-add-thin-controllers) is passed to
`createDesignStudioApi({ baseApi, apiPrefix })`. Installation, the provider contract, the Tailwind setup and a wiring
example: [`web/README.md`](web/README.md).

## Thumbnail renderer example

[`renderer/`](renderer/README.md) is a working example of the optional thumbnail renderer (a stateless Node service)
with the HTTP contract the module expects.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite runs without an application kernel and without a database (`tests/bootstrap.php` installs a minimal
container and registers the module's config directory).

The frontend package has its own checks (`web/README.md`, "Development"):

```bash
cd web
pnpm install
pnpm typecheck && pnpm test && pnpm build
```
