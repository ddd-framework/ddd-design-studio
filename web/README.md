# @mgamadeus/ddd-design-studio

The React frontend of the design review studio for backends built with
[mgamadeus/ddd](https://github.com/ddd-framework/ddd): an overview of design projects, the canvas of a project (every
board laid out per section, live sandboxed HTML at close zoom, thumbnails further out), the focus view of one board,
comment threads with pins, review statuses and plain-text design references for agents.

It talks to the API of the Composer package
[`mgamadeus/ddd-design-studio`](https://github.com/ddd-framework/ddd-design-studio) (same repository, same version
number). Whatever it needs from the application around it (API client, router, UI primitives, session, storage, texts)
comes in through one provider; it contains no application code.

## Install

```bash
npm install @mgamadeus/ddd-design-studio
# or
pnpm add @mgamadeus/ddd-design-studio
```

ESM only. Peer dependencies (installed by the application):

| Package | Range |
|---|---|
| `react`, `react-dom` | `^19.0.0` |
| `@reduxjs/toolkit` | `^2.6.0` |
| `react-redux` | `^9.0.0` |
| `@mgamadeus/ddd-web` | `^0.3.0` |
| `@xyflow/react` | `^12.12.1` (loaded only by the `/canvas` entry) |
| `lucide-react` | `>=0.550.0 <2` |

`clsx` and `tailwind-merge` are regular dependencies.

**pnpm `minimumReleaseAge`:** pnpm 10+ refuses versions younger than the configured age (24 h by default in many
setups), so `pnpm install --frozen-lockfile` fails in CI right after a release. Either wait, or exclude the package in
`pnpm-workspace.yaml` and commit that line together with the lockfile:

```yaml
minimumReleaseAgeExclude:
  - '@mgamadeus/ddd-design-studio'
```

## Entries

| Import | Content | Loads `@xyflow/react` |
|---|---|---|
| `@mgamadeus/ddd-design-studio` | `createDesignStudioApi`, `DesignStudioProvider` and its hooks, `DesignStudioOverviewScreen`, `DesignStudioProjectScreen`, `DesignStudioReviewsScreen`, the reference and theme helpers, the default texts, all types | no |
| `@mgamadeus/ddd-design-studio/canvas` | `DesignStudioCanvasScreen` | yes, with its stylesheet (`@xyflow/react/dist/style.css`) |

Every file of the package starts with `'use client'`: all exports are client code, including the pure helpers. The
canvas entry imports a stylesheet, so it needs a bundler that handles CSS imports from packages (Next.js, Vite and
webpack with a CSS loader do). It measures the DOM and renders sandboxed iframes: load it client-only and lazily.

## Requirements of the application

- **Tailwind 4 with shadcn tokens.** The package ships no CSS of its own. Its class names are Tailwind utilities, so
  Tailwind must scan the package's build output. In the application's main stylesheet (path relative to that file):

  ```css
  @import 'tailwindcss';
  @source '../node_modules/@mgamadeus/ddd-design-studio/dist';
  ```

  The class names use the shadcn colour tokens (`bg-card`, `bg-popover`, `text-muted-foreground`, `bg-primary`,
  `text-primary-foreground`, `border-border`, `ring-ring`, `bg-destructive`, …) and these additional names, which the
  application's stylesheet defines:
  - colours: `brand`, `brand-light`, `brand-emphasis`, `tint`, `tint-strong`, `info`, `success`, `warning`
  - utilities / tokens: `surface-glass-strong`, `shadow-surface-raised`, `text-title-card`, `--nav-h` (height of the
    shell's top bar; the canvas fills `100dvh - var(--nav-h)`)
- **One RTK Query api** created with `createApi` from `@reduxjs/toolkit/query/react` on a fetch-style base query
  (`createDddBaseQuery` of `@mgamadeus/ddd-web/api`), its reducer and middleware in the store.
- **UI primitives** with shadcn "new-york" names and behaviour, plus a few page blocks (see
  [Environment](#environment-designstudioenvironment)).
- **Routes** for the studio's pages (see [Routes](#routes-the-application-creates)).

## API

```ts
const designStudioApi = createDesignStudioApi({ baseApi, apiPrefix: '/api/admin/designStudio' });
```

`createDesignStudioApi` calls `baseApi.enhanceEndpoints({ addTagTypes: ['DesignStudio'] }).injectEndpoints(…)` once and
returns the hooks. One cache tag: every query provides it, every mutation invalidates it.

| Hook | Request (below `apiPrefix`) |
|---|---|
| `useOverviewQuery` | `GET /overview` |
| `useThemesQuery` | `GET /themes` |
| `useCanvasQuery` | `GET /canvases/{projectKey}/{canvasKey}` |
| `useCanvasRevisionQuery` | `GET /canvases/{projectKey}/{canvasKey}/revision` |
| `useCanvasCommentsQuery` | `GET /canvases/{projectKey}/{canvasKey}/comments` |
| `useExpiringCommentsQuery` | `GET /comments/expiring` |
| `useRequestThumbnailRenderingMutation` | `POST /canvases/{projectKey}/{canvasKey}/thumbnailRenderRequests` |
| `useSetBoardStatusMutation` | `PATCH /canvases/{projectKey}/{canvasKey}/boards/{boardKey}/status` |
| `useCreateCommentMutation` | `POST /canvases/{projectKey}/{canvasKey}/boards/{boardKey}/comments` |
| `useReplyToCommentMutation` | `POST /comments/{designStudioCommentId}/replies` |
| `usePatchCommentMutation` | `PATCH /comments/{designStudioCommentId}` |
| `useDeleteCommentMutation` | `DELETE /comments/{designStudioCommentId}` |
| `useResolveCommentMutation` | `POST /comments/{designStudioCommentId}/resolve` |
| `useReopenCommentMutation` | `POST /comments/{designStudioCommentId}/reopen` |
| `useKeepCommentMutation` | `POST /comments/{designStudioCommentId}/keep` |

Request bodies are passed as `body`; the canvas, revision, comments and render-request operations take the optional
`theme` query parameter (`<themeId>[:<mode>]`).

## Environment (`DesignStudioEnvironment`)

Pass one stable object (a module constant) to `DesignStudioProvider`. Entries named `use…` are hooks.

| Entry | Type | Purpose |
|---|---|---|
| `api` | `DesignStudioApi` | the hooks from `createDesignStudioApi` |
| `apiBaseUrl` | `string` | origin prefixed to the delivery URLs of boards and thumbnails; `''` = same origin |
| `router.Link` | component `{ href, className, children }` | in-app link |
| `router.useSearchParams` | `() => { get(key) }` | the current search parameters (reactive); the canvas reads `?board=` and `?code=`, the overview `?code=` |
| `router.useSearchParamsWriter` | `() => (updates) => void` | replaces search parameters of the current page: no history entry, no scroll jump, other parameters kept, same-tick writes merged; `null` removes one |
| `router.useUrlState` | `(keys, { stateId, debounceMs, uncounted }) => { values, debounced, setValue }` | the canvas state (`q`, `section`, `status`, `theme`, `mode`, `vp`) in the URL, restored from what the application remembered under `stateId` when the URL has none of the keys |
| `router.useNavigate` (optional) | `() => (href) => void` | client navigation to an in-app path (query allowed, with a history entry); the overview uses it to open a board code; without it the overview shows a link to click |
| `paths` | `overview()`, `project(projectKey)`, `canvas(projectKey, canvasKey)`, optional `site(projectKey, canvasKey, route)` | in-app paths of the studio's pages |
| `components` | `DesignStudioComponents` | UI slots: `Button`, `Badge`, `Card`, `CardHeader`, `CardTitle`, `CardContent`, `Skeleton`, `Checkbox`, `Label`, `Textarea`, `Dialog`, `DialogContent`, `DialogTitle`, `DialogDescription`, `Popover`, `PopoverTrigger`, `PopoverContent`, `Tooltip`, `TooltipTrigger`, `TooltipContent`, `DropdownMenu`, `DropdownMenuTrigger`, `DropdownMenuContent`, `DropdownMenuItem`, `DropdownMenuSeparator`, `Combobox`, `SearchField`, `QuickFilterPills`, `StatusBadge`, `PageHeader`, `StatusCallout`, `CardGrid`, `PageSections` — each typed with only the props the studio passes ([`src/provider/types.ts`](src/provider/types.ts), interface `DesignStudioComponents`) |
| `notify` | `success(message)`, `error(message)`, `info(message, { id, action })` | toasts |
| `formatDateTime` | `(value) => string` | a backend date-time for the viewer |
| `storage` | `read(key)`, `write(key, value)` | per-viewer persistence; keys: `commentsPanelOpen` |
| `useCanWrite` | `() => boolean` | enables the write controls |
| `useCurrentAccountId` | `() => number \| null` | the signed-in account (own comments can be deleted) |
| `shell.useSidebarCollapsed` | optional `() => [isCollapsed, toggle]` | the canvas collapses the shell's sidebar while it is open |
| `statuses` | `{ [statusKey]: { label, tone, icon?, description? } }` | presentation per review status; an unknown key shows as it is |
| `statusColors` | optional `{ [statusKey]: cssColour }` | minimap colour per review status |
| `texts` | optional `Partial<DesignStudioTexts>` | replacements for UI texts (see [Texts](#texts-designstudiotexts)) |

Inside the package, components read it with `useDesignStudio()` or the narrower `useDesignStudioApi()`,
`useDesignStudioComponents()`, `useDesignStudioRouter()`, `useDesignStudioPaths()`, `useDesignStudioNotify()`,
`useDesignStudioTexts()`.

## Texts (`DesignStudioTexts`)

`environment.texts` replaces UI texts of the canvas; an entry left out keeps its English default
(`DEFAULT_DESIGN_STUDIO_TEXTS`, [`src/provider/texts.ts`](src/provider/texts.ts)). Entries with parameters are functions.

| Entry | Default |
|---|---|
| `referencesCopied(count)` | `Reference copied` / `References copied (3)` |
| `boardsUpdated(count)` | `1 board updated` / `3 boards updated` |
| `showUpdatedBoard` | `Show` |
| `canvasUpToDate` | `Canvas is up to date` |
| `reload` | `Reload (R)` |
| `thumbnailsFailed` | `Thumbnails failed · try again` |
| `thumbnailsWaitingForRenderer(themeLabel)` | `Thumbnails for <theme> are waiting for the renderer` |
| `thumbnailsRendering(themeLabel)` | `Thumbnails for <theme> are being rendered` |
| `thumbnailsFallbackThemeLabel` | `this look` (stands in for a theme without a label) |
| `thumbnailsMissing(count)` | `3 boards have no thumbnail in this look yet; they appear as soon as they are rendered.` |
| `thumbnailsLastError(error)` | `Last error: <error>` |
| `themeWithoutDarkMode` | `This theme has no dark mode` |
| `thumbnailMissing` | `No thumbnail yet` |
| `thumbnailLoading` | `Loading…` |
| `sitePageMissing(path)` | `No page for <path>` |
| `siteExternalLink(href)` | `External link, not followed: <href>` |
| `openAsWebsite` | `Open as website` |
| `referenceNotePlaceholder` | `What should change? (optional, goes into the reference)` |
| `boardCodeNotFound(code)` | `No board has the code <code>` |
| `boardLinkCopied(code)` | `Link to <code> copied` |
| `copyBoardLink` | `Copy link` |

```ts
const ENVIRONMENT: DesignStudioEnvironment = {
  // …
  texts: { reload: 'Refresh (R)', boardsUpdated: count => `${count} screens changed` },
};
```

The other texts of the studio are fixed English strings.

## Routes the application creates

The package has no router. The application creates one page per screen and tells the studio their paths through
`environment.paths`:

| Page | Screen | `paths` entry |
|---|---|---|
| overview of the projects | `DesignStudioOverviewScreen` | `overview()` |
| one project and its canvases | `DesignStudioProjectScreen` (`projectKey`) | `project(projectKey)` |
| one canvas | `DesignStudioCanvasScreen` (`projectKey`, `canvasKey`) from `/canvas` | `canvas(projectKey, canvasKey)` |
| a canvas as a website (optional) | `DesignStudioSiteScreen` (`projectKey`, `canvasKey`, `route`: the rest of the path, e.g. `/pricing`) | `site(projectKey, canvasKey, route)`; without it the canvas offers no "Open as website" |
| reviews (optional; an empty state in this version) | `DesignStudioReviewsScreen` | none: linked by the application's own navigation |

The canvas page keeps its state in search parameters: `board` (the focus view), `q`, `section`, `status`, `theme`,
`mode`, `vp`. `code` opens a board by its code (see Board codes) and is removed from the URL once handled. Project and canvas keys in a URL should be validated by the application before a screen is rendered.

## Wiring with the Next.js App Router

A minimal example. `@/…` stands for the application's own modules.

```ts
// src/design-studio/designStudioApi.ts: once, at module scope
import { createDesignStudioApi } from '@mgamadeus/ddd-design-studio';

import { baseApi } from '@/api/baseApi';

export const designStudioApi = createDesignStudioApi({ baseApi, apiPrefix: '/api/admin/designStudio' });
```

```tsx
// src/design-studio/AppDesignStudioProvider.tsx: the environment built from the application's own pieces
'use client';

import {
  DesignStudioProvider,
  type DesignStudioEnvironment,
  type DesignStudioSearchParamUpdates,
  type DesignStudioUrlStateOptions,
} from '@mgamadeus/ddd-design-studio';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';

import { designStudioApi } from './designStudioApi';

type AppRouter = ReturnType<typeof useRouter>;

// Writes of one tick are merged into one `router.replace` (the canvas writes several parameters at once).
let pendingUpdates: Record<string, string | null> | null = null;
const queueSearchParamsWrite = (pathname: string, router: AppRouter, updates: DesignStudioSearchParamUpdates) => {
  if (!pendingUpdates) {
    pendingUpdates = {};
    queueMicrotask(() => {
      const next = new URLSearchParams(window.location.search);
      for (const [key, value] of Object.entries(pendingUpdates ?? {})) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      pendingUpdates = null;
      const query = next.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    });
  }
  Object.assign(pendingUpdates, updates);
};

/** Replaces search parameters of the current page (no history entry); `null` / `''` removes one. */
const useSearchParamsWriter = () => {
  const pathname = usePathname();
  const router = useRouter();
  return useCallback(
    (updates: DesignStudioSearchParamUpdates) => queueSearchParamsWrite(pathname, router, updates),
    [pathname, router]
  );
};

/**
 * The canvas state in the URL: read from the URL on mount, else restored from this tab's sessionStorage under
 * `stateId`; written back (URL + storage) `debounceMs` after the last change. Defaults (`''`) are never written.
 */
const useUrlState = <K extends string>(keys: readonly K[], { stateId, debounceMs }: DesignStudioUrlStateOptions) => {
  const searchParams = useSearchParams();
  const writeSearchParams = useSearchParamsWriter();
  const storageKey = `app.${stateId}`;
  const [values, setValues] = useState(() => {
    const fromUrl = Object.fromEntries(keys.map(key => [key, searchParams.get(key) ?? ''])) as Record<K, string>;
    if (keys.some(key => fromUrl[key]) || typeof window === 'undefined') return fromUrl;
    try {
      return { ...fromUrl, ...(JSON.parse(window.sessionStorage.getItem(storageKey) ?? '{}') as Partial<Record<K, string>>) };
    } catch {
      return fromUrl;
    }
  });
  const [debounced, setDebounced] = useState(values);
  const keysRef = useRef(keys);
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(values);
      writeSearchParams(Object.fromEntries(keysRef.current.map(key => [key, values[key] || null])));
      try {
        window.sessionStorage.setItem(storageKey, JSON.stringify(values));
      } catch {
        // storage blocked: the URL still holds the state
      }
    }, debounceMs);
    return () => clearTimeout(timer);
  }, [values, debounceMs, storageKey, writeSearchParams]);
  const setValue = useCallback((key: K, value: string) => setValues(current => ({ ...current, [key]: value })), []);
  return { values, debounced, setValue };
};

/** Client navigation (the overview opens a board code on its canvas). */
const useNavigate = () => {
  const router = useRouter();
  return useCallback((href: string) => router.push(href), [router]);
};

const ENVIRONMENT: DesignStudioEnvironment = {
  // …
  texts: { reload: 'Refresh (R)', boardsUpdated: count => `${count} screens changed` },
};
```

The other texts of the studio are fixed English strings.

## Routes the application creates

The package has no router. The application creates one page per screen and tells the studio their paths through
`environment.paths`:

| Page | Screen | `paths` entry |
|---|---|---|
| overview of the projects | `DesignStudioOverviewScreen` | `overview()` |
| one project and its canvases | `DesignStudioProjectScreen` (`projectKey`) | `project(projectKey)` |
| one canvas | `DesignStudioCanvasScreen` (`projectKey`, `canvasKey`) from `/canvas` | `canvas(projectKey, canvasKey)` |
| a canvas as a website (optional) | `DesignStudioSiteScreen` (`projectKey`, `canvasKey`, `route`: the rest of the path, e.g. `/pricing`) | `site(projectKey, canvasKey, route)`; without it the canvas offers no "Open as website" |
| reviews (optional; an empty state in this version) | `DesignStudioReviewsScreen` | none: linked by the application's own navigation |

The canvas page keeps its state in search parameters: `board` (the focus view), `q`, `section`, `status`, `theme`,
`mode`, `vp`. `code` opens a board by its code (see Board codes) and is removed from the URL once handled. Project and canvas keys in a URL should be validated by the application before a screen is rendered.

## Wiring with the Next.js App Router

A minimal example. `@/…` stands for the application's own modules.

```ts
// src/design-studio/designStudioApi.ts: once, at module scope
import { createDesignStudioApi } from '@mgamadeus/ddd-design-studio';

import { baseApi } from '@/api/baseApi';

export const designStudioApi = createDesignStudioApi({ baseApi, apiPrefix: '/api/admin/designStudio' });
```

```tsx
// src/design-studio/AppDesignStudioProvider.tsx: the environment built from the application's own pieces
'use client';

import { DesignStudioProvider, type DesignStudioEnvironment } from '@mgamadeus/ddd-design-studio';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { type ReactNode, useCallback } from 'react';

import { designStudioApi } from './designStudioApi';

/**
 * Replaces search parameters of the current page; `null` removes one. Simplified: the contract also asks for writes
 * made in the same tick to be merged into one navigation (queue them and flush in a microtask).
 */
const useSearchParamsWriter = () => {
  const pathname = usePathname();
  const router = useRouter();
  return useCallback(
    (updates: Readonly<Record<string, string | null>>) => {
      const next = new URLSearchParams(window.location.search);
      for (const [key, value] of Object.entries(updates)) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      const query = next.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router]
  );
};

const ENVIRONMENT: DesignStudioEnvironment = {
  api: designStudioApi,
  apiBaseUrl: '',
  router: { Link, useSearchParams, useSearchParamsWriter, useUrlState, useNavigate },
  paths: {
    overview: () => '/design',
    project: projectKey => `/design/${projectKey}`,
    canvas: (projectKey, canvasKey) => `/design/${projectKey}/canvases/${canvasKey}`,
    // optional: the site view; the page `/design/[projectKey]/canvases/[canvasKey]/site/[[...route]]` passes
    // `'/' + (route ?? []).join('/')` to DesignStudioSiteScreen
    site: (projectKey, canvasKey, route) => `/design/${projectKey}/canvases/${canvasKey}/site${route === '/' ? '' : route}`,
  },
  components: { Button, Badge, Card /* … every slot of DesignStudioComponents */ },
  notify: { success: toast.success, error: toast.error, info: toast.info },
  formatDateTime,
  storage: {
    read: key => readLocal(`app.designStudio.${key}`),
    write: (key, value) => writeLocal(`app.designStudio.${key}`, value),
  },
  useCanWrite: () => useAppSelector(selectCanWrite),
  useCurrentAccountId: () => useAppSelector(selectAccountId),
  statuses: {
    DRAFT: { label: 'Draft', tone: 'neutral' },
    IN_REVIEW: { label: 'In review', tone: 'info' },
    CHOSEN: { label: 'Chosen', tone: 'success' },
    REJECTED: { label: 'Rejected', tone: 'danger' },
  },
};

export const AppDesignStudioProvider = ({ children }: { children: ReactNode }) => (
  <DesignStudioProvider environment={ENVIRONMENT}>{children}</DesignStudioProvider>
);
```

```tsx
// src/app/design/page.tsx
import { DesignStudioOverviewScreen } from '@mgamadeus/ddd-design-studio';

import { AppDesignStudioProvider } from '@/design-studio/AppDesignStudioProvider';

export default function DesignOverviewPage() {
  return (
    <AppDesignStudioProvider>
      <DesignStudioOverviewScreen />
    </AppDesignStudioProvider>
  );
}
```

```tsx
// src/app/design/[projectKey]/page.tsx
import { DesignStudioProjectScreen } from '@mgamadeus/ddd-design-studio';

import { AppDesignStudioProvider } from '@/design-studio/AppDesignStudioProvider';

export default async function DesignProjectPage({ params }: { params: Promise<{ projectKey: string }> }) {
  const { projectKey } = await params;
  return (
    <AppDesignStudioProvider>
      <DesignStudioProjectScreen projectKey={projectKey} />
    </AppDesignStudioProvider>
  );
}
```

```tsx
// src/design-studio/DesignCanvasLoader.tsx: the canvas, client-only and lazy
'use client';

import dynamic from 'next/dynamic';

import { AppDesignStudioProvider } from './AppDesignStudioProvider';

const DesignStudioCanvasScreen = dynamic(
  () => import('@mgamadeus/ddd-design-studio/canvas').then(module => module.DesignStudioCanvasScreen),
  { ssr: false }
);

export const DesignCanvasLoader = ({ projectKey, canvasKey }: { projectKey: string; canvasKey: string }) => (
  <AppDesignStudioProvider>
    {/* `key`: one canvas keeps nothing of another */}
    <DesignStudioCanvasScreen key={`${projectKey}/${canvasKey}`} projectKey={projectKey} canvasKey={canvasKey} />
  </AppDesignStudioProvider>
);
```

```tsx
// src/app/design/[projectKey]/canvases/[canvasKey]/page.tsx
import { Suspense } from 'react';

import { DesignCanvasLoader } from '@/design-studio/DesignCanvasLoader';

export default async function DesignCanvasPage({
  params,
}: {
  params: Promise<{ projectKey: string; canvasKey: string }>;
}) {
  const { projectKey, canvasKey } = await params;
  return (
    // the canvas screen reads search parameters
    <Suspense>
      <DesignCanvasLoader projectKey={projectKey} canvasKey={canvasKey} />
    </Suspense>
  );
}
```

## Board codes

A canvas with a `code` in the backend config gives every board a short code (`APP ONB-W2`: project code, canvas code,
board part from the file name or `canvas.json`). The code is shown in the board header and in the selected board's
panel, the canvas search finds boards by it (`w2`, `ONB-W2`), and references carry it (`code APP ONB-W2`).

- **Code link of a canvas:** `<canvas page>?code=APP-ONB-W2` (also `ONB-W2` or `W2`). The canvas moves the view to the
  board, selects it and shows its panel; the parameter is then removed (a reload keeps the place the viewer moved to).
  "Copy link" in the panel copies this link with the current look.
- **Short link:** `<overview page>?code=APP-ONB-W2` finds the canvas by the code and opens it there (needs
  `router.useNavigate`; without it the overview shows a link). The overview also has a field to type a code.
- Helpers (pure, exported from the main entry): `boardCodeMatches`, `findBoardForCode`, `codeBelongsToCanvas`,
  `buildBoardCodeLink`, `boardCodeUrlValue`, `normalizeBoardCode`, `CODE_PARAM`.

## Reference helpers

`buildDesignReference(input)` builds the plain-text block that names one board (optionally a region, a comment thread,
a note) for an agent; `buildBoardDeepLink(pageUrl, boardKey, themeId, mode, viewport?)` the link that reopens the board
in the focus view. Both are pure and exported from the main entry.

## Development

```bash
pnpm install
pnpm typecheck
pnpm test        # node:test on src/**/*.test.ts (Node 22.6 or newer)
pnpm build       # tsup -> dist (ESM + .d.ts), then scripts/check-package.mjs
```

| Path | Content |
|---|---|
| `src/index.ts` | the main entry (does not load `@xyflow/react`) |
| `src/canvas/index.ts` | the `/canvas` entry: `DesignStudioCanvasScreen` |
| `src/api/` | `createDesignStudioApi` and the types of the backend contract (maintained by hand) |
| `src/provider/` | `DesignStudioProvider`, its hooks, the `DesignStudioEnvironment` types, the default texts |
| `src/screens/` | overview, project and reviews screens |
| `src/canvas/` | the canvas screen, the canvas engine, focus view, comments, and the pure modules with their tests |
| `scripts/check-package.mjs` | checks a build or an extracted tarball: export targets, the entry split, `'use client'` |
| `test/consumer/` | installs the packed tarball into a fresh project and type-checks a strict consumer |

Source files import only `react`, `react-dom`, `@xyflow/react`, `lucide-react`, `@reduxjs/toolkit`, `react-redux`,
`@mgamadeus/ddd-web/*`, `clsx`, `tailwind-merge` and relative files; only files below `src/canvas/` that the main
entry does not reach may import `@xyflow/react`. The published package contains `dist` only (no sources, no tests).

## Release

The version in `package.json` equals the version in the repository's `composer.json`. Pushing the tag `v<version>`
runs `.github/workflows/release.yml`, which verifies both versions against the tag, builds, checks the tarball and
publishes it to npm through trusted publishing.

## License

MIT
