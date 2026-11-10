import type { LucideIcon } from 'lucide-react';
import type {
  ButtonHTMLAttributes,
  ComponentType,
  HTMLAttributes,
  InputHTMLAttributes,
  ReactNode,
  TextareaHTMLAttributes,
} from 'react';

import type { DesignStudioApi } from '../api/createDesignStudioApi';

// --- router ---------------------------------------------------------------------------------------------------------

/** Search-parameter updates; `null` or `''` removes the parameter. */
export type DesignStudioSearchParamUpdates = Readonly<Record<string, string | null>>;

export interface DesignStudioLinkProps {
  /** An in-app path as `paths` builds it. */
  href: string;
  className?: string;
  children?: ReactNode;
}

export interface DesignStudioUrlStateOptions {
  /**
   * Stable id of the state (`designCanvas.<projectKey>.<canvasKey>`): the application remembers the state under it and
   * hands it back when the page is opened without the parameters.
   */
  stateId: string;
  /** Delay between a change and the URL write / the settled value (ms). */
  debounceMs: number;
  /** Keys that do not count as filters. */
  uncounted: readonly string[];
}

/** A set of string values kept in the URL (`''` = unset). */
export interface DesignStudioUrlState<K extends string> {
  /** What the inputs show (updates at once). */
  values: Readonly<Record<K, string>>;
  /** The settled values (`debounceMs` after the last change). */
  debounced: Readonly<Record<K, string>>;
  /** Stable across renders. */
  setValue: (key: K, value: string) => void;
}

/** The application's router, as far as the studio needs it. Hooks follow the rules of hooks. */
export interface DesignStudioRouter {
  /** In-app link (client navigation, the application's base path prepended). */
  Link: ComponentType<DesignStudioLinkProps>;
  /** The current search parameters (reactive). */
  useSearchParams: () => { get: (key: string) => string | null };
  /**
   * A writer for search parameters of the current page: replaces the URL without a history entry and without a scroll
   * jump, keeps every other parameter, and merges writes made in the same tick into one navigation.
   */
  useSearchParamsWriter: () => (updates: DesignStudioSearchParamUpdates) => void;
  /**
   * The canvas state (search, filters, theme, mode, viewport) in the URL: read from the URL on mount, else restored
   * from what the application remembered under `stateId`; written back debounced through the same merged writer.
   * `keys` is a module constant.
   */
  useUrlState: <K extends string>(keys: readonly K[], options: DesignStudioUrlStateOptions) => DesignStudioUrlState<K>;
}

// --- paths ----------------------------------------------------------------------------------------------------------

/** In-app paths of the studio's pages (without the application's base path). */
export interface DesignStudioPaths {
  overview: () => string;
  project: (projectKey: string) => string;
  canvas: (projectKey: string, canvasKey: string) => string;
}

// --- statuses -------------------------------------------------------------------------------------------------------

export type DesignStudioStatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'highlight' | 'brand';

/** How one review status is shown. */
export interface DesignStudioStatusDefinition {
  label: string;
  tone: DesignStudioStatusTone;
  icon?: LucideIcon;
  /** Hover text. */
  description?: string;
}

/**
 * Presentation per status key. The keys themselves are configured in the backend (`DesignStudioCanvasView.statuses`,
 * the first one is the default); a key without a definition shows as it is, in the neutral tone.
 */
export type DesignStudioStatuses = Readonly<Partial<Record<string, DesignStudioStatusDefinition>>>;

// --- components -----------------------------------------------------------------------------------------------------

interface WithChildren {
  children?: ReactNode;
}

interface WithClassName extends WithChildren {
  className?: string;
}

interface AsChildTriggerProps extends WithChildren {
  asChild?: boolean;
}

export interface DesignStudioOption {
  value: string;
  label: string;
}

export type DesignStudioButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'default' | 'outline' | 'ghost';
  size?: 'sm' | 'icon' | 'icon-sm';
};

export type DesignStudioBadgeProps = HTMLAttributes<HTMLSpanElement> & { variant?: 'default' | 'outline' };

export interface DesignStudioCheckboxProps {
  id?: string;
  checked?: boolean;
  onCheckedChange?: (checked: boolean | 'indeterminate') => void;
  disabled?: boolean;
}

export interface DesignStudioDialogProps extends WithChildren {
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}

export interface DesignStudioDialogContentProps extends WithClassName {
  /** `false`: the dialog draws its own close button. */
  showCloseButton?: boolean;
}

export interface DesignStudioComboboxProps {
  options: readonly DesignStudioOption[];
  /** `''` = nothing picked. */
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  searchPlaceholder?: string;
  isLoading?: boolean;
  size?: 'sm';
  /** `pill`: a picker in a bar, without the field's edge. */
  shape?: 'pill';
  className?: string;
  'aria-label'?: string;
}

export interface DesignStudioSearchFieldProps
  extends Pick<InputHTMLAttributes<HTMLInputElement>, 'placeholder' | 'aria-label' | 'className'> {
  value: string;
  onChange: (value: string) => void;
  clearable?: boolean;
  size?: 'sm';
}

export interface DesignStudioQuickFilterPillsProps {
  options: readonly DesignStudioOption[];
  /** `''` = all. */
  value: string;
  onChange: (value: string) => void;
  /** A leading pill that clears the filter. */
  allLabel?: string;
  size?: 'sm';
  'aria-label': string;
}

export interface DesignStudioStatusBadgeProps {
  tone: DesignStudioStatusTone;
  label: ReactNode;
  icon?: LucideIcon;
  title?: string;
  className?: string;
}

export interface DesignStudioPageHeaderProps {
  title: ReactNode;
  description?: ReactNode;
  /** Before the title (an icon). */
  leading?: ReactNode;
}

export interface DesignStudioStatusCalloutProps {
  /** Default: an informational notice. */
  tone?: 'destructive';
  title: ReactNode;
  description?: ReactNode;
  /** Replaces the tone's icon. */
  icon?: LucideIcon;
  className?: string;
}

/**
 * The application's UI primitives (shadcn "new-york" names and behaviour) and page blocks, typed with only the props
 * the studio passes. The studio styles them further with Tailwind classes through `className`.
 */
export interface DesignStudioComponents {
  Button: ComponentType<DesignStudioButtonProps>;
  Badge: ComponentType<DesignStudioBadgeProps>;
  Card: ComponentType<WithClassName>;
  CardHeader: ComponentType<WithClassName>;
  CardTitle: ComponentType<WithClassName>;
  CardContent: ComponentType<WithClassName>;
  Skeleton: ComponentType<{ className?: string }>;
  Checkbox: ComponentType<DesignStudioCheckboxProps>;
  Label: ComponentType<WithClassName & { htmlFor?: string }>;
  Textarea: ComponentType<TextareaHTMLAttributes<HTMLTextAreaElement>>;
  Dialog: ComponentType<DesignStudioDialogProps>;
  DialogContent: ComponentType<DesignStudioDialogContentProps>;
  DialogTitle: ComponentType<WithClassName>;
  DialogDescription: ComponentType<WithClassName>;
  Popover: ComponentType<WithChildren>;
  PopoverTrigger: ComponentType<AsChildTriggerProps>;
  PopoverContent: ComponentType<WithClassName & { align?: 'start' | 'center' | 'end' }>;
  Tooltip: ComponentType<WithChildren>;
  TooltipTrigger: ComponentType<AsChildTriggerProps>;
  TooltipContent: ComponentType<WithClassName & { side?: 'top' | 'right' | 'bottom' | 'left' }>;
  DropdownMenu: ComponentType<WithChildren & { modal?: boolean }>;
  DropdownMenuTrigger: ComponentType<AsChildTriggerProps>;
  DropdownMenuContent: ComponentType<WithChildren & { align?: 'start' | 'center' | 'end' }>;
  DropdownMenuItem: ComponentType<WithChildren & { onSelect?: () => void }>;
  DropdownMenuSeparator: ComponentType<Record<never, never>>;
  /** Single-choice picker with a search field. */
  Combobox: ComponentType<DesignStudioComboboxProps>;
  /** The search box (magnifier inside, clear button while there is text). */
  SearchField: ComponentType<DesignStudioSearchFieldProps>;
  /** A row of filter pills, one picked at most. */
  QuickFilterPills: ComponentType<DesignStudioQuickFilterPillsProps>;
  /** Status pill: tone colour, icon, label. */
  StatusBadge: ComponentType<DesignStudioStatusBadgeProps>;
  /** Title block of a page. */
  PageHeader: ComponentType<DesignStudioPageHeaderProps>;
  /** A notice box (empty state, error). */
  StatusCallout: ComponentType<DesignStudioStatusCalloutProps>;
  /** Cards side by side. */
  CardGrid: ComponentType<{ children: ReactNode }>;
  /** The vertical rhythm between the sections of a page. */
  PageSections: ComponentType<{ children: ReactNode }>;
}

// --- notify, storage, shell -----------------------------------------------------------------------------------------

export interface DesignStudioToastOptions {
  /** A toast with the same id replaces the one on screen. */
  id?: string;
  action?: { label: string; onClick: () => void };
}

/** Toasts. */
export interface DesignStudioNotify {
  success: (message: string) => void;
  error: (message: string) => void;
  info: (message: string, options?: DesignStudioToastOptions) => void;
}

/** The values the studio persists in the browser, per viewer. */
export type DesignStudioStorageKey =
  /** `'1'` / `'0'`: the canvas' comments panel is open (nothing stored = open). */
  'commentsPanelOpen';

/** Persistent per-viewer storage; the application maps the keys to its own registry. Must not throw. */
export interface DesignStudioStorage {
  read: (key: DesignStudioStorageKey) => string | null;
  write: (key: DesignStudioStorageKey, value: string) => void;
}

/** The application shell around the studio's pages. */
export interface DesignStudioShell {
  /**
   * The shell's sidebar: `[isCollapsed, toggle]`. With it the canvas collapses the sidebar while it is open and
   * restores it when the page is left.
   */
  useSidebarCollapsed?: () => readonly [boolean, () => void];
}

// --- texts ----------------------------------------------------------------------------------------------------------

/**
 * The studio's UI texts an application can replace (`environment.texts`); every entry has an English default
 * (`provider/texts.ts`). Entries with parameters are functions.
 */
export interface DesignStudioTexts {
  /** Toast after copying design references to the clipboard (`count` = number of references, 1 or more). */
  referencesCopied: (count: number) => string;
  /** Toast of a live update: `count` boards changed on disk and were reloaded. */
  boardsUpdated: (count: number) => string;
  /** Action of the live-update toast: scrolls to the first changed board. */
  showUpdatedBoard: string;
  /** Toast of a manual reload that found no change. */
  canvasUpToDate: string;
  /** Label and tooltip of the reload button (the key is R). */
  reload: string;
  /** Thumbnail pill: the render request failed; a click files it again. */
  thumbnailsFailed: string;
  /** Thumbnail pill: the request waits for the renderer service (`themeLabel` = the shown theme). */
  thumbnailsWaitingForRenderer: (themeLabel: string) => string;
  /** Thumbnail pill: thumbnails are being rendered (`themeLabel` = the shown theme). */
  thumbnailsRendering: (themeLabel: string) => string;
  /** Stands in for `themeLabel` when the shown theme has no label. */
  thumbnailsFallbackThemeLabel: string;
  /** Tooltip of the thumbnail pill: `count` boards have no thumbnail in the shown look yet. */
  thumbnailsMissing: (count: number) => string;
  /** Appended to the tooltip of the thumbnail pill when the last render request reported an error. */
  thumbnailsLastError: (error: string) => string;
  /** Tooltip of the disabled dark-mode button of a theme without a dark variant. */
  themeWithoutDarkMode: string;
  /** Shown on a board card that has no thumbnail yet. */
  thumbnailMissing: string;
  /** Placeholder of the note field in the focus view's reference panel. */
  referenceNotePlaceholder: string;
}

// --- environment ----------------------------------------------------------------------------------------------------

/** Everything the studio takes from the application it runs in (`DesignStudioProvider`). */
export interface DesignStudioEnvironment {
  /** The studio's API hooks: `createDesignStudioApi({ baseApi, apiPrefix })`. */
  api: DesignStudioApi;
  /** Origin the delivery URLs of boards and thumbnails (`/api/...`) are prefixed with; `''` = same origin. */
  apiBaseUrl: string;
  router: DesignStudioRouter;
  paths: DesignStudioPaths;
  components: DesignStudioComponents;
  notify: DesignStudioNotify;
  /** Formats a backend date-time (offsetless UTC) for the viewer. */
  formatDateTime: (value: string | null | undefined) => string;
  storage: DesignStudioStorage;
  /** Whether write controls are enabled (the application's write guard / the viewer's rights). */
  useCanWrite: () => boolean;
  /** The signed-in account's id (own comments can be deleted), null while unknown. */
  useCurrentAccountId: () => number | null;
  shell?: DesignStudioShell;
  /** Label, tone and icon per review status key. */
  statuses: DesignStudioStatuses;
  /** Minimap colour (a CSS colour) per review status key; a status without one is drawn muted. */
  statusColors?: Readonly<Record<string, string>>;
  /** Replacements for the studio's UI texts; an entry left out keeps its English default. */
  texts?: Partial<DesignStudioTexts>;
}
