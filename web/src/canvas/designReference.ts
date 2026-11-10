/**
 * Design references for agentic editing ("send to agent"): a plain-text block that names one
 * board (and optionally a region of it, a comment, a note) with stable keys, so an agent session can resolve it.
 * Format (one line per board, fields joined by " · ", the deep link always last; optional
 * extra lines below):
 *
 *   [design-ref] App › Onboarding – first run › 2 Welcome · file welcome-2.dc.html · canvas app/onboarding
 *     · v3f9c2ab · theme brand:1:dark · region 24,180,342,220
 *     · https://…/app/canvases/onboarding?board=welcome-2.dc.html&theme=brand:1&mode=dark
 *   note: make the tab bar more compact
 *
 * `file` is the board key (the file name in the canvas folder), `v…` the first 7 characters of the board file's
 * content hash (git blob hash), `theme` the theme reference the board was looked at in (`none` = as authored).
 *
 * Pure functions, no React (tested in designReference.test.ts).
 */

export const DESIGN_REFERENCE_PREFIX = '[design-ref]';

/** A rectangle on a board in board pixels (independent of the zoom). */
export interface BoardRegion {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface DesignReferenceInput {
  projectName: string;
  canvasName: string;
  projectKey: string;
  canvasKey: string;
  boardTitle: string;
  /** File name of the board in the canvas folder. */
  boardKey: string;
  /** Content hash of the board file (git blob hash); the reference carries its first 7 characters. */
  contentHash: string | null;
  /** Theme reference the board is shown in (`<themeId>` or `<themeId>:dark`), null = as authored. */
  themeReference: string | null;
  /** Deep link to the board on the canvas (with theme and mode). */
  url: string;
  region?: BoardRegion | null;
  /** A comment thread the reference is about (id + pin position). */
  comment?: { id: number | string; x: number | null; y: number | null; lines: string[] } | null;
  /** What should change (free text). */
  note?: string | null;
}

const oneLine = (text: string) => text.replace(/\s+/g, ' ').trim();

/** One reference block (first line + optional `comment:` / `note:` lines). */
export const buildDesignReference = (input: DesignReferenceInput): string => {
  const fields = [
    `${DESIGN_REFERENCE_PREFIX} ${oneLine(input.projectName)} › ${oneLine(input.canvasName)} › ${oneLine(input.boardTitle)}`,
    `file ${input.boardKey}`,
    `canvas ${input.projectKey}/${input.canvasKey}`,
    `v${input.contentHash ? input.contentHash.slice(0, 7) : '?'}`,
    `theme ${input.themeReference || 'none'}`,
  ];
  if (input.region) {
    const { x, y, width, height } = input.region;
    fields.push(`region ${Math.round(x)},${Math.round(y)},${Math.round(width)},${Math.round(height)}`);
  }
  if (input.comment) {
    const position =
      input.comment.x != null && input.comment.y != null
        ? ` at ${Math.round(input.comment.x)},${Math.round(input.comment.y)}`
        : '';
    fields.push(`comment ${input.comment.id}${position}`);
  }
  fields.push(input.url);
  const lines = [fields.join(' · ')];
  for (const commentLine of input.comment?.lines ?? []) {
    if (commentLine.trim()) lines.push(`comment: ${oneLine(commentLine)}`);
  }
  if (input.note?.trim()) lines.push(`note: ${oneLine(input.note)}`);
  return lines.join('\n');
};

/**
 * Deep link to a board: the current page URL with `board` (the board key), the look (`theme`, `mode`; left out when
 * the board is shown as authored) and, when given, the canvas viewport `vp` (other canvas parameters dropped) — the
 * link reopens the focus view over the same part of the canvas.
 */
export const buildBoardDeepLink = (
  pageUrl: string,
  boardKey: string,
  themeId: string | null,
  mode: 'light' | 'dark',
  viewport?: string | null
): string => {
  const url = new URL(pageUrl);
  url.search = '';
  url.hash = '';
  url.searchParams.set('board', boardKey);
  if (themeId) {
    url.searchParams.set('theme', themeId);
    url.searchParams.set('mode', mode);
  }
  if (viewport) url.searchParams.set('vp', viewport);
  // commas and colons are valid in a query; unescaped, the viewport and the theme stay readable in a pasted reference
  return url.toString().replace(/%2C/gi, ',').replace(/%3A/gi, ':');
};

/**
 * Copies text: the async Clipboard API, else a hidden textarea + `execCommand('copy')` (older browsers, insecure
 * origins).
 * @returns whether the text reached the clipboard
 */
export const copyTextToClipboard = async (text: string): Promise<boolean> => {
  if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // fall through to the textarea fallback (permission denied, document not focused)
    }
  }
  if (typeof document === 'undefined') return false;
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();
  const isCopied = document.execCommand('copy');
  textarea.remove();
  return isCopied;
};
