---
name: ddd-module-design-studio-specialist
description: "Installs, wires and operates the ddd-design-studio module, a review studio for the HTML design boards of a checkout. Covers the directory model (canvas.json, board files, version = git blob hash), board images from the canvas assets folder instead of base64, config DesignStudio projects, api, content, statuses, retention, storage, renderer, thin controllers on the three traits, the public content route and its token, object storage, the optional thumbnail renderer (Messenger handler, worker), binding DesignAccessPolicyService and DesignThemeProviderService, board codes (APP ONB-W2) and code links, and app:design-studio:show, comments, comments-poll, decisions, status, theme, thumbnails, prune. Use when installing the studio, adding a canvas, putting photos into boards, linking to a board, answering design comments as an agent, setting a board status, requesting thumbnails, or debugging an empty canvas, a missing board or image, No handler for message, missing thumbnails or a 404 on board content."
metadata:
  author: mgamadeus
  version: "0.3.0"
  module: mgamadeus/ddd-design-studio
---

# Design Studio Module Specialist

Use the `mgamadeus/ddd-design-studio` module in an application: install and wire it, configure projects, bind the
access policy and themes, work with comments, statuses and thumbnails through the console commands, and debug it.

> Base patterns: See core skills in vendor/mgamadeus/ddd.

For Argus repos see `vendor/mgamadeus/ddd-argus`. For changing the module itself see its `AGENTS.md`. The complete
configuration, route and command reference is the module's `README.md`.

## When to Use

- Installing the studio in an application or adding a project / canvas
- Working as an agent with review feedback: read threads, reply, resolve, record decisions, set statuses
- Switching thumbnails on (renderer, Messenger handler, worker)
- Restricting who may use the studio, or offering themes
- Debugging: empty canvas, missing board, "No handler for message", thumbnails that never appear, 404 on board content

## Mental model (read first)

The directory says what exists; the database stores only what people said.

- A **canvas** is a folder of the running checkout with `canvas.json` and one `.html` file per board. Projects and
  canvases are declared in the config `DesignStudio.projects`. None of it is stored in the database.
- A **board key** is the file name. A **board version** is the git blob hash of the file content. Compare a local file
  with what the studio shows:

  ```bash
  git hash-object design/onboarding/welcome.html
  ```

- The database holds comment threads, board statuses, thumbnails and thumbnail render requests. They name a board by
  project key, canvas key and board key, and a version by content hash.
- Every process reads **its own** file system: the web process for the canvas, the console command for its checkout,
  the worker for rendering. A board that is not in that process' checkout does not exist for it.

## Critical rules

1. **Config files are replaced as a whole**, not merged. An application file under `config/app/DesignStudio/` must
   name every key of the module file it replaces.
2. **The canvas folder must be in the deployed image** (web and worker). Otherwise the canvas is empty with the
   warning `No <folder>/canvas.json in this checkout`.
3. **The content route is public at the firewall** and must sit at `DesignStudio.api.contentBasePath`. Access comes
   only from the token in the URL. Do not put other routes under that path.
4. **The Messenger handler is not auto-registered.** Thumbnails need the `services.yaml` tag below; the console
   commands need nothing.
5. **Resolve the extension-point services by the module class id** (`DesignAccessPolicyService::class`,
   `DesignThemeProviderService::class`), never by the application subclass and never with `new`.
6. **Console commands act as the CLI account** (`CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS`). That account must pass
   `DesignAccessPolicyService::canView()` (default: an admin role), or lists are empty and writes are refused.

## Install and wire

1. `composer require mgamadeus/ddd-design-studio`
2. Create the tables through the schema diff (`ddd-database-schema-diff-specialist`): `DesignStudioComments`,
   `DesignStudioBoardStatuses`, `DesignStudioThumbnails`, `DesignStudioThumbnailRenderRequests`.
3. Declare projects in `config/app/DesignStudio/projects.php` (next section).
4. Make sure the canvas folders are part of the image.
5. Add three thin controllers on the application's admin base controller, one per trait from
   `DDD\Presentation\Api\Admin\DesignStudio\Traits\`:
   `DesignStudioCanvasesControllerTrait`, `DesignStudioCommentsControllerTrait`, `DesignStudioContentControllerTrait`.
   The routes are defined in the traits; the class only carries the route prefix and the tag:

   ```php
   #[Route('/designStudio/content')]
   class DesignStudioContentController extends AdminController
   {
       use DesignStudioContentControllerTrait;
   }
   ```

   The content controller's full path must equal `DesignStudio.api.contentBasePath` (default
   `/api/admin/designStudio/content`); otherwise override `config/app/DesignStudio/api.php`.
6. Open the content path in `security.yaml`, above the admin rule:

   ```yaml
   - { path: ^/api/admin/designStudio/content, roles: PUBLIC_ACCESS }
   ```

7. Configure the object store named in `DesignStudio.storage.store` (default `design-studio`): a `stores` entry in
   `config/app/Common/ObjectStorage.php` or `OBJECT_STORAGE_DESIGN_STUDIO_*` environment variables.
8. Verify: `php bin/console app:design-studio:show` lists the projects with their board counts.

## Frontend

The studio's screens are the npm package `@mgamadeus/ddd-design-studio` (folder `web/` of the module repository, same
version as the Composer package): `npm install @mgamadeus/ddd-design-studio`. The application passes the API prefix of
its thin controllers to `createDesignStudioApi({ baseApi, apiPrefix })`, builds one `DesignStudioEnvironment` (API
hooks, router, UI primitives, session, storage, optional `texts`) for `DesignStudioProvider`, creates the pages
(overview, project, canvas) and lets Tailwind scan the package (`@source` on its `dist`). The canvas screen is the
separate entry `@mgamadeus/ddd-design-studio/canvas`: load it client-only and lazily. Full contract and a wiring
example: `web/README.md` of the module repository (the README of the npm package).

## Configure projects and canvases

```php
// config/app/DesignStudio/projects.php
return [
    [
        'key' => 'app',
        'label' => 'App',
        'path' => 'app',
        'code' => 'APP',                           // optional: first part of every board code
        'canvases' => [
            [
                'key' => 'onboarding',
                'label' => 'Onboarding',
                'folder' => 'design/onboarding',   // relative to the project root, no '..'
                'boards' => ['*'],                 // fnmatch patterns of board file names
                'sections' => ['*'],               // fnmatch patterns of note keys that are section titles
                'referenceTheme' => null,          // e.g. 'brand:1' or 'brand:1:dark'
                'code' => 'ONB',                   // optional: board codes `APP ONB-W2`
            ],
        ],
    ],
];
```

- Keys: lower-case letters, digits, hyphens; must start with a letter or digit; at most 63 characters; unique. An
  invalid key or folder fails every request with a `BadRequestException` ("Design studio config: …").
- `boards` empty = every board of `canvas.json`. Several canvases may share one folder and split it by patterns.
- `sections` empty = every note whose `kind` starts with `title`.
- `code` (project and canvas, optional): 1–12 letters and digits starting with a letter, stored in upper case. A canvas
  with a code gives its boards **board codes** `<project code> <canvas code>-<board part>` (`APP ONB-W2`). The board
  part is `code` of the board in `canvas.json`, else derived from the file name: without `.html` / `.dc.html`, split at
  `-` / `_`, a first part equal to the canvas code left out, then the parts up to and including the first one with a
  digit, in upper case (`onb-w-2-welcome.dc.html` → `W2`, `onb-s01-start.html` → `S01`). No digit → no code; two
  boards with the same board part both lose it (warning). Two canvases may not share `<project code> <canvas code>`.
  Codes are read like the rest of the directory (`DesignBoardCodesService`), never stored; renaming a file can change
  its code. Links: `<canvas page>?code=APP-ONB-W2` moves the canvas to the board and selects it; `<overview
  page>?code=APP-ONB-W2` finds the canvas first.
- Keys are part of the stored review state. Renaming a project or canvas key, or a board file, detaches existing
  comments and statuses from it.

`canvas.json`:

```json
{
    "boards": { "welcome.html": { "x": 100, "y": 1100, "w": 390, "h": 844, "title": "Welcome" } },
    "notes": { "title-start": { "x": 100, "y": 1000, "text": "Start", "kind": "title" } }
}
```

Board defaults: `x` / `y` 0, `w` 390, `h` 844, `title` = file name. A board belongs to the lowest section title with
`y <= board.y`. An entry is skipped silently when its key is not a plain `.html` file name, starts with a dot or does
not match the canvas' `boards` patterns. A missing or oversized file is left out with a warning.

## Images in boards: `assets/`

Put images into `<canvas folder>/assets/` and reference them relative to the canvas folder; do not embed them as
base64 (every board would carry every photo, and each regeneration adds megabytes of new git objects):

```html
<img src="assets/photos/start-hero-m.webp" alt="">
<img srcset="assets/photos/hero.webp 1x, assets/photos/hero@2x.webp 2x">
<section style="background-image: url('./assets/photos/team.webp')"></section>
```

- Recognised in `src`, `href`, `poster`, `srcset` and CSS `url(…)`; `assets/<path>` or `./assets/<path>`.
- Types `webp`, `png`, `jpg`, `jpeg`, `gif`, `avif`, `svg`; at most `DesignStudio.content.assetMaximumBytes` (5 MiB by
  default; the application's `content.php` must then name all three keys). Segments: letters, digits, `.`, `_`, `-`; no
  dotfiles, no `..`, no symbolic link out of `assets/`.
- The board file stays unchanged; the studio rewrites the references on delivery (token and file version in the URL).
  Opened from disk (a local render script, a browser) the relative paths work as they are.
- Replacing a photo changes the board's `assetsHash`: the boards that show it reload and get new thumbnails; the board
  version (`git hash-object`) does not change.
- A thread keeps the referenced files with its snapshot, so an earlier commented version shows its old images.
- The `assets/` folder must be deployed with the canvas folder (web and worker image).

## Operate through the console commands

Arguments are the project key and the canvas key. Run the commands in the checkout whose boards are meant.

### Look

```bash
php bin/console app:design-studio:show                                  # projects, canvases, counts
php bin/console app:design-studio:show app onboarding                   # boards by section: version, status, open threads
php bin/console app:design-studio:show app onboarding --open            # only boards with open threads
php bin/console app:design-studio:show app onboarding --board=welcome.html   # one board with its threads
php bin/console app:design-studio:show --board="APP ONB-W2"            # one board by its code (project and canvas found by it)
```

Warnings of the directory read are printed at the end of the canvas listing.

### Comments

```bash
# list (grouped by board, replies below their thread root)
php bin/console app:design-studio:comments app onboarding --open
php bin/console app:design-studio:comments app onboarding --board=welcome.html --sinceId=120

# start a thread (optionally pinned, optionally a decision)
php bin/console app:design-studio:comments app onboarding --new="Spacing above the button" --board=welcome.html --x=120 --y=640
php bin/console app:design-studio:comments app onboarding --new="We keep variant B" --board=welcome.html --decision

# act on a thread by the id of any of its comments
php bin/console app:design-studio:comments app onboarding --reply=42 --text="Changed, please check"
php bin/console app:design-studio:comments app onboarding --resolve=42 --text="done"
php bin/console app:design-studio:comments app onboarding --reopen=42
php bin/console app:design-studio:comments app onboarding --keep=42
php bin/console app:design-studio:comments app onboarding --delete=42
```

- `--as="<name>"` sets the author name shown in the studio (default `Agent`). Console comments always have the author
  kind `AGENT`.
- One action per call. `--new` needs `--board`; `--reply` needs `--text`; the comment must be on the named canvas.
- `--delete` on a thread root deletes its replies too.
- A new thread is written on the board version of this checkout and keeps a snapshot of it. Commit or sync the board
  first if the comment should refer to the version other people see.

### Wait for feedback

```bash
php bin/console app:design-studio:comments-poll app onboarding --sinceId=0 --wait=300
```

Prints the comments with a larger id (waiting up to `--wait` seconds for the first one, looking every 5 seconds), then
`sinceId=<newest id>`. Pass that value to the next call.

### Typical agent loop

1. `comments … --open` to read the open threads; `show … --board=<file>` marks threads whose board changed since.
2. Change the board file.
3. `comments … --resolve=<id> --text="<what was done>"` (or `--reply` when a question remains).
4. `status … <board> IN_REVIEW` when the board is ready to be looked at again.

### Statuses, decisions, themes, retention

```bash
php bin/console app:design-studio:status app onboarding welcome.html IN_REVIEW --as="Design agent"
php bin/console app:design-studio:decisions app onboarding          # decisions log as Markdown
php bin/console app:design-studio:theme                             # list themes (--search=<text>)
php bin/console app:design-studio:theme brand:1:dark                # print the theme's <style> element
php bin/console app:design-studio:prune --dryRun                    # expired threads + threads expiring soon
```

- Status keys come from `DesignStudio.statuses` (default `DRAFT`, `IN_REVIEW`, `CHOSEN`, `REJECTED`); the first is the
  status of a board without a stored one. An unknown key is refused with the configured list.
- A thread without activity for `DesignStudio.retention.threadIdleDays` (default 180) is deleted; decisions never,
  unless `decisionIdleDays` is set. Reply, resolve, reopen and `--keep` count as activity. Opening a canvas runs the
  deletion at most once a day; `prune` runs it on demand.

## Thumbnails (optional)

Off by default (`DesignStudio.renderer.enabled` = `false`): the canvas view then carries no thumbnail URLs.

1. Application `config/app/DesignStudio/renderer.php`: copy the module file, set `enabled` to `true`, keep every key.
   `endpoint` is `POST:<path>` (relative to the Argus base URL) or `POST:<absolute URL>`; the environment variable named
   in `endpointEnv` replaces it; the one named in `tokenEnv` is sent as `X-Render-Token`.
2. `services.yaml`:

   ```yaml
   DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsHandler:
       tags:
           - { name: messenger.message_handler, from_transport: render_design_studio_thumbnails }
       bind:
           Psr\Log\LoggerInterface $messengerLogger: '@monolog.logger.messenger'
   ```

3. `messenger.yaml`:

   ```yaml
   framework:
       messenger:
           transports:
               render_design_studio_thumbnails: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/render_design_studio_thumbnails'
           routing:
               'DDD\Domain\DesignStudio\MessageHandlers\RenderDesignStudioThumbnailsMessage': render_design_studio_thumbnails
   ```

4. Rebuild the container, then run consumers: `php bin/console messenger:consume render_design_studio_thumbnails`.
   A request runs in `concurrency` shards (default 2, at most 8), one message each; run at least that many consumer
   processes. Worker setup and the stale-container trap: `ddd-message-handler-specialist`.
5. Check and request:

   ```bash
   php bin/console app:design-studio:thumbnails app onboarding                    # "N of M boards have a thumbnail" + newest request
   php bin/console app:design-studio:thumbnails app onboarding --render           # files a request for the missing ones
   php bin/console app:design-studio:thumbnails app onboarding --theme=brand:1:dark --render
   ```

Request statuses: `QUEUED`, `RUNNING`, `WAITING_FOR_RENDERER` (renderer answered 401, 404, 429, 502 to 504 or was
unreachable; retried with backoff), `DONE`, `FAILED`. A thumbnail key is derived from content hash, theme hash and
`rendererVersion`: changing `rendererVersion` makes every thumbnail render anew.

## Bind the access policy and themes

Subclass, register the subclasses as public services **and** alias the module's service ids to them:

```yaml
# the application's own classes, public (a resource block of the namespace is enough)
App\Domain\DesignStudio\Services\:
    resource: '../src/Domain/DesignStudio/Services/'
    public: true

DDD\Domain\DesignStudio\Services\DesignAccessPolicyService:
    class: App\Domain\DesignStudio\Services\AppDesignAccessPolicyService
    public: true

DDD\Domain\DesignStudio\Services\DesignThemeProviderService:
    class: App\Domain\DesignStudio\Services\AppDesignThemeProviderService
    public: true
```

Without the public registration of the subclasses themselves the console commands fail with `The
"App\…\AppDesignAccessPolicyService" service or alias has been removed or inlined when the container was compiled`:
the framework's service lookup also asks for the concrete class. The module itself always resolves the module class id.

```php
class AppDesignAccessPolicyService extends DesignAccessPolicyService
{
    public function canView(?Account $account): bool
    {
        return parent::canView($account) && /* the application's own rule */ true;
    }

    public function getAccountDisplayName(Account $account): string
    {
        return 'Account ' . $account->id;   // replace with the application's name of an account
    }
}
```

- Policy methods: `canView()`, `canComment()`, `canSetStatus()`, `canDecide()` (the last three default to `canView()`),
  `getAccountDisplayName()`. `canView()` also gates every database row of the module and the content token.
- Theme provider methods to override: `findThemes(?string $searchText)`, `getTheme(string $themeId)`,
  `getThemeStyle(string $themeId, string $mode)`. `getThemeStyle()` returns a complete `<style data-design-theme>`
  element (CSS variables); it is placed first in the board's `<head>`. A reference is `<themeId>` or
  `<themeId>:<mode>`, mode `light` or `dark`. Returning `null` means "unknown theme": the board is shown as it is.
- Themes with identical CSS share their thumbnails (the theme hash is the SHA-256 of the style element).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `The "App\…\App…Service" service or alias has been removed or inlined` | The access policy / theme subclass is only aliased; register the subclass itself as a public service (Bind the access policy and themes) |
| Console shows "Blocked script execution in …/content/boards/…" | Not from the module: the delivered board has no `<script>` (removed, and the CSP says `script-src 'none'`). Browser extensions that inject scripts into every frame trigger it in the sandboxed iframe; harmless |
| Canvas is empty, warning `No <folder>/canvas.json in this checkout` | The folder is not in the image / checkout of the answering process, the `folder` value is wrong, or it resolves outside the project root (symbolic link). Check the folder on the server, not locally |
| `show` works locally but the studio page is empty | Different checkouts. The web process reads its own file system; deploy the canvas folder |
| A board of `canvas.json` is missing, no warning | Its key is not a plain `.html` file name, starts with a dot, or does not match the canvas' `boards` patterns |
| Warning `Board file <name> of canvas.json is missing` / `is too large` | The file is absent, or larger than `DesignStudio.content.boardMaximumBytes` (16 MiB by default) |
| 400 `… is not valid JSON` / `has no "boards" object` / `is too large` | Fix `canvas.json` (limit: `canvasJsonMaximumBytes`) |
| Every studio request fails with `Design studio config: …` | Invalid or duplicate key, or unsafe folder in `DesignStudio.projects` |
| Board iframe or thumbnail answers 404 | By design every failure is a 404: content route not public at the firewall or not at `contentBasePath`; token missing or expired (reload the canvas view for new URLs); the account lost `canView()`; requested version neither in the checkout nor kept as a snapshot |
| Board iframe answers 401 / 403 | The firewall still protects the content path; add the `PUBLIC_ACCESS` rule above the admin rule |
| An image of a board is missing | The reference is not `assets/…` / `./assets/…` in `src`, `href`, `poster`, `srcset` or `url()`; the type is not an image type; the path has a dot segment or other characters; the file is larger than `assetMaximumBytes`, resolves outside `assets/`, or is not in the deployed image |
| Old version of a commented board cannot be shown | No object store was configured when the thread started (no snapshot), or the snapshot object is gone |
| `No handler for message "…RenderDesignStudioThumbnailsMessage"` | The handler tag is missing in `services.yaml`, or the worker runs a container built before it was added. Add the tag, rebuild the container, restart the workers |
| Render request stays `QUEUED` | No consumer on `render_design_studio_thumbnails`, transport or routing missing, or the message's account fails `canView()` in the worker. After 20 minutes without change the next request replaces it |
| Render request `WAITING_FOR_RENDERER` | See `lastError` (`app:design-studio:thumbnails …`): endpoint not reachable (404), token missing or wrong (401), renderer busy (429) |
| Request `DONE` but thumbnails are missing | No object store configured (thumbnails cannot be stored), or single boards failed: see `lastError` |
| `app:design-studio:thumbnails` prints `No renderer is configured` | `DesignStudio.renderer.enabled` is `false` in the effective config (an application file replaces the module file as a whole) |
| Console lists nothing / "may not" errors | `CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS` is unset or its account fails the access policy |
| `Comment #<id> is not on this canvas` | Wrong project / canvas arguments for that comment id, or the comment was deleted |
| A setting of the module default disappeared | The application file for that config does not name the key; config files are not merged |

## Checklist (new installation)

- [ ] Package required, four tables created
- [ ] `config/app/DesignStudio/projects.php` declares the projects; canvas folders are in the image
- [ ] Three thin controllers use the three traits; content controller at `DesignStudio.api.contentBasePath`
- [ ] Content path is `PUBLIC_ACCESS` in `security.yaml`
- [ ] Object store `design-studio` configured
- [ ] `app:design-studio:show` lists projects and board counts
- [ ] With thumbnails: renderer config, handler tag, transport, routing, consumers, container rebuilt
- [ ] Optional bindings registered under the module's service ids

## Cross-Reference

- Schema diff and table creation: `ddd-database-schema-diff-specialist`
- Messenger transports, workers, stale container: `ddd-message-handler-specialist`
- Controllers and DTOs: `ddd-endpoint-specialist`
- Object storage: `ddd-service-specialist`
- Argus configuration (base URL of a relative renderer endpoint): `ddd-module-argus-specialist`
