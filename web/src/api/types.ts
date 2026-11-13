/**
 * The design studio's API contract: the entities, response bodies and request arguments of the 15 operations the studio
 * calls (`createDesignStudioApi`). Field names are the backend's (the DesignStudio domain of a DDD application); these
 * types are maintained here by hand and are the package's contract with the backend.
 */

/** Created / modified times of a stored entity (offsetless UTC `Y-m-d H:i:s`). */
export interface DesignStudioChangeHistory {
  createdTime?: string | null;
  modifiedTime?: string | null;
  objectType?: string | null;
}

/** A comment on a board: a thread root (`parentDesignStudioCommentId` null) or a reply. */
export interface DesignStudioComment {
  /** Key of the design project */
  projectKey: string | null;
  /** Key of the canvas within the project */
  canvasKey: string | null;
  /** File name of the board in the canvas folder */
  boardKey: string | null;
  /** Content hash of the board file the comment was written on (git blob hash) */
  contentHash: string | null;
  /** The thread root this comment replies to; null = a thread root */
  parentDesignStudioCommentId?: number | null;
  parentDesignStudioComment?: DesignStudioComment;
  /** Pin position from the board's left edge in board pixels; null = a comment on the whole board */
  x?: number | null;
  /** Pin position from the board's top edge in board pixels */
  y?: number | null;
  /** Viewport width the pin was placed in (a responsive board: x / y are page positions at this width); null = a fixed board */
  viewportWidth?: number | null;
  /** Viewport height the pin was placed in */
  viewportHeight?: number | null;
  /** Device preset the pin was placed in; null = a free width or a fixed board */
  deviceKey?: string | null;
  /** The comment */
  text: string | null;
  /** Id of the account that wrote it; no relation, the account may be gone */
  authorAccountId?: number | null;
  /** Author as shown in the studio */
  authorName?: string | null;
  /** `USER`: written by a person in the studio · `AGENT`: written by an agent through the console commands */
  authorKind: ('USER' | 'AGENT') | null;
  /** Resolved (thread roots only) */
  isResolved: boolean | null;
  /** When it was resolved */
  resolvedAt?: string | null;
  /** Who resolved it */
  resolvedByName?: string | null;
  /** Marks a decision (decisions log); decisions do not expire unless configured */
  isDecision: boolean | null;
  /** Object key of the board file as it was when the thread started (thread roots only) */
  snapshotObjectKey?: string | null;
  /** Last activity in the thread (comment, reply, resolve, reopen, keep); set on the thread root, drives expiry */
  lastActivityAt?: string | null;
  objectType?: string | null;
  /** The internal identifier of the entity */
  id?: string | number | null;
  changeHistory?: DesignStudioChangeHistory;
}

export interface DesignStudioComments {
  elements?: DesignStudioComment[];
  objectType?: string | null;
}

/** The stored review status of a board. */
export interface DesignStudioBoardStatus {
  projectKey: string | null;
  canvasKey: string | null;
  boardKey: string | null;
  /** Status key (one of the configured statuses) */
  status: string | null;
  /** Content hash of the board file when the status was set (git blob hash) */
  contentHash?: string | null;
  /** Id of the account that set the status; no relation, the account may be gone */
  changedByAccountId?: number | null;
  /** Who set the status, as shown in the studio */
  changedByName?: string | null;
  objectType?: string | null;
  id?: string | number | null;
  changeHistory?: DesignStudioChangeHistory;
}

/** A section of a canvas (a titled group of boards). */
export interface DesignDirectorySection {
  /** Key of the section in the canvas definition */
  sectionKey?: string | null;
  /** Section title */
  title?: string | null;
  /** Left edge of the title, relative to the canvas selection */
  x?: number;
  /** Top edge of the title, relative to the canvas selection */
  y?: number;
  /** Width the title may take, 0 = unbounded */
  maximumWidth?: number;
  /** Position within the canvas, top to bottom, starting at 1 */
  displayOrder?: number;
  objectType?: string | null;
}

export interface DesignDirectorySections {
  elements?: DesignDirectorySection[];
  objectType?: string | null;
}

/** One board of a canvas as the studio shows it. */
export interface DesignStudioBoardView {
  /** File name of the board in the canvas folder */
  boardKey?: string | null;
  /** Short code of the board (`APP ONB-W2`): names it in chats and opens it on the canvas, null = none */
  code?: string | null;
  /** Board title */
  title?: string | null;
  /** Left edge, relative to the canvas selection */
  x?: number;
  /** Top edge, relative to the canvas selection */
  y?: number;
  /** Width in pixels */
  width?: number;
  /** Height in pixels */
  height?: number;
  /** Key of the section the board belongs to */
  sectionKey?: string | null;
  /** Position within the canvas, starting at 1 */
  displayOrder?: number;
  /** Content hash of the board file (git blob hash) */
  contentHash?: string | null;
  /** Review status (one of the configured statuses; the default without a stored one) */
  status?: string | null;
  /** The board file changed after the status was set */
  hasChangedSinceStatus?: boolean;
  /** Comment threads on the board that are not resolved */
  openThreadCount?: number;
  /** URL of the board for the sandboxed iframe (carries the content token) */
  htmlUrl?: string | null;
  /** URL of the thumbnail in the requested look, null when there is none (yet) */
  thumbnailUrl?: string | null;
  /** URL of the reduced thumbnail for far zoom levels, null when there is none (yet) */
  thumbnailSmallUrl?: string | null;
  /** URL of the 640 px preview (WebP / JPEG) for middle zoom levels, null when there is no thumbnail (yet) */
  thumbnailPreviewUrl?: string | null;
  /** The layout adapts to the viewport width: the board is shown in device frames */
  isResponsive?: boolean;
  /** Device frames of a responsive board, side by side (width / height of the board = their extent); null for a fixed board */
  frames?: DesignStudioBoardFrameViews | null;
  /** Path of the board's page in the site view (`/`, `/pricing`), null = no page */
  route?: string | null;
  objectType?: string | null;
}

/** One device frame of a responsive board. */
export interface DesignStudioBoardFrameView {
  /** Key of the device */
  deviceKey?: string | null;
  /** Viewport width in CSS pixels */
  width?: number;
  /** Viewport height in CSS pixels (frame height in viewport mode) */
  height?: number;
  /** Height of the whole page at this width as rendered; null until the frame has a thumbnail */
  pageHeight?: number | null;
  /** Thumbnail of the whole page at this width, null until rendered */
  thumbnailUrl?: string | null;
  /** Reduced thumbnail, null until rendered */
  thumbnailSmallUrl?: string | null;
  /** 640 px preview (WebP / JPEG) for the canvas, null until rendered */
  thumbnailPreviewUrl?: string | null;
  objectType?: string | null;
}

export interface DesignStudioBoardFrameViews {
  elements?: DesignStudioBoardFrameView[];
  objectType?: string | null;
}

/** A device preset responsive boards are shown in. */
export interface DesignDevice {
  /** Key of the preset, e.g. `iphone-16` */
  deviceKey?: string | null;
  /** Name shown in the picker */
  label?: string | null;
  /** Viewport width in CSS pixels */
  width?: number;
  /** Viewport height in CSS pixels */
  height?: number;
  kind?: ('DESKTOP' | 'TABLET' | 'PHONE') | null;
  objectType?: string | null;
}

export interface DesignDevices {
  elements?: DesignDevice[];
  objectType?: string | null;
}

export interface DesignStudioBoardViews {
  elements?: DesignStudioBoardView[];
  objectType?: string | null;
}

/** A canvas with its sections and boards in one look. */
export interface DesignStudioCanvasView {
  projectKey?: string | null;
  canvasKey?: string | null;
  /** What every board code of the canvas starts with (`APP ONB`), null when the canvas has no code */
  code?: string | null;
  /** Name of the canvas */
  label?: string | null;
  /** Reference of the theme the delivery URLs and thumbnails are in, null = no theme */
  themeReference?: string | null;
  /** Reference of the theme the canvas names as its usual look */
  referenceTheme?: string | null;
  sections?: DesignDirectorySections;
  boards?: DesignStudioBoardViews;
  /** Problems found while reading the folder (missing files, oversized boards) */
  warnings?: string[];
  /** The configured status keys in display order */
  statuses?: string[];
  /** Fingerprint of boards, comments and statuses */
  revision?: string | null;
  /** Thumbnails are rendered and shown */
  isRendererEnabled?: boolean;
  /** Boards without a thumbnail in the requested look */
  missingThumbnailCount?: number;
  /** Status of the newest render request for the look, null without one */
  renderRequestStatus?: string | null;
  /** Last renderer problem of that request */
  renderRequestError?: string | null;
  /** Device presets responsive boards can be shown in */
  devices?: DesignDevices;
  objectType?: string | null;
}

export interface DesignStudioCanvasSummary {
  canvasKey?: string | null;
  /** What every board code of the canvas starts with (`APP ONB`), null when the canvas has no code */
  code?: string | null;
  /** Name of the canvas */
  label?: string | null;
  /** Reference of the theme the canvas names as its usual look */
  referenceTheme?: string | null;
  /** Boards in the checkout */
  boardCount?: number;
  /** Comment threads that are not resolved */
  openThreadCount?: number;
  /** Decisions recorded on the canvas */
  decisionCount?: number;
  objectType?: string | null;
}

export interface DesignStudioCanvasSummaries {
  elements?: DesignStudioCanvasSummary[];
  objectType?: string | null;
}

export interface DesignStudioProjectSummary {
  projectKey?: string | null;
  /** Name of the project */
  label?: string | null;
  canvases?: DesignStudioCanvasSummaries;
  objectType?: string | null;
}

export interface DesignStudioProjectSummaries {
  elements?: DesignStudioProjectSummary[];
  objectType?: string | null;
}

/** Every project with its canvases, and the studio's configuration. */
export interface DesignStudioOverview {
  projects?: DesignStudioProjectSummaries;
  /** The configured status keys in display order; the first is the default */
  statuses?: string[];
  /** Thumbnails are rendered and shown */
  isRendererEnabled?: boolean;
  /** The application offers themes (looks) for the boards */
  hasThemes?: boolean;
  /** Days a thread may be idle before it is deleted */
  threadIdleDays?: number;
  /** Threads that will be deleted soon unless something happens in them */
  expiringThreadCount?: number;
  objectType?: string | null;
}

/** A look the application offers for the boards. */
export interface DesignTheme {
  /** Id the application's theme provider resolves (no `:light` / `:dark` suffix) */
  themeId?: string | null;
  /** Name shown in the theme switcher */
  label?: string | null;
  /** The theme has a dark look of its own */
  supportsDark?: boolean;
  objectType?: string | null;
}

export interface DesignThemes {
  elements?: DesignTheme[];
  objectType?: string | null;
}

// Response bodies

export interface DesignStudioOverviewResponse {
  designStudioOverview: DesignStudioOverview;
}

export interface DesignStudioThemesResponse {
  designThemes: DesignThemes;
}

export interface DesignStudioCanvasResponse {
  designStudioCanvasView: DesignStudioCanvasView;
}

export interface DesignStudioCanvasRevisionResponse {
  /** Changes with every board file, position, comment and status of the canvas */
  revision: string;
}

export interface DesignStudioBoardStatusResponse {
  designStudioBoardStatus: DesignStudioBoardStatus;
}

export interface DesignStudioCommentResponse {
  designStudioComment: DesignStudioComment;
}

export interface DesignStudioCommentsResponse {
  designStudioComments: DesignStudioComments;
}

export interface DesignStudioDeleteResponse {
  /** true when the deletion was carried out */
  isDeleted: boolean;
}

// Request arguments

/** Query parameters every operation of a DDD backend accepts. */
export interface DesignStudioSharedParams {
  noCache?: boolean;
  outputFormat?: string;
}

export interface DesignStudioThemesArg extends DesignStudioSharedParams {
  /** Text the theme name contains */
  search?: string;
}

export interface DesignStudioCanvasArg extends DesignStudioSharedParams {
  projectKey: string;
  canvasKey: string;
  /** Theme reference `<themeId>[:<mode>]` (mode light or dark); omitted = the reference theme of the canvas */
  theme?: string;
}

export interface DesignStudioCanvasCommentsArg extends DesignStudioCanvasArg {
  /** true: only comments of threads that are not resolved */
  onlyOpenThreads?: boolean;
  /** Only comments with a larger id (polling) */
  sinceId?: number;
}

export interface DesignStudioThumbnailRenderRequestArg extends DesignStudioCanvasArg {
  body: {
    /** Theme reference `<themeId>[:<mode>]`; omitted = the reference theme of the canvas */
    theme?: string | null;
    /** Boards on screen, rendered first */
    priorityBoardKeys?: string[] | null;
  };
}

export interface DesignStudioBoardArg extends DesignStudioSharedParams {
  projectKey: string;
  canvasKey: string;
  boardKey: string;
}

export interface DesignStudioBoardStatusPatchArg extends DesignStudioBoardArg {
  body: {
    /** One of the configured status keys */
    status: string;
  };
}

export interface DesignStudioCommentCreateArg extends DesignStudioBoardArg {
  body: {
    /** The comment text */
    text: string;
    /** Pin position from the left edge in board pixels; omitted = a comment on the whole board */
    x?: number | null;
    /** Pin position from the top edge in board pixels */
    y?: number | null;
    /** Viewport width the pin was placed in (a responsive board); omitted = board pixels */
    viewportWidth?: number | null;
    /** Viewport height the pin was placed in */
    viewportHeight?: number | null;
    /** Device preset the pin was placed in; omitted = a free width */
    deviceKey?: string | null;
    /** Marks the comment as a decision */
    isDecision?: boolean | null;
  };
}

export interface DesignStudioCommentArg extends DesignStudioSharedParams {
  /** The id of the comment (a thread root for replies, resolve, reopen and keep) */
  designStudioCommentId: number;
}

export interface DesignStudioCommentReplyArg extends DesignStudioCommentArg {
  body: {
    /** The reply text */
    text: string;
  };
}

export interface DesignStudioCommentPatchArg extends DesignStudioCommentArg {
  body: {
    /** New text (only the author may change it) */
    text?: string | null;
    /** Sets or clears the decision flag */
    isDecision?: boolean | null;
  };
}

export interface DesignStudioCommentResolveArg extends DesignStudioCommentArg {
  body: {
    /** Reply written before the thread is resolved */
    replyText?: string | null;
  };
}
