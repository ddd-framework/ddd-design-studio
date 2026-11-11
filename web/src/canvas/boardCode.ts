/**
 * Board codes (`APP ONB-W2`): the short name of a board that people say in a chat or paste into the studio. The
 * backend sets them (project code + canvas code + a board part from the file name or canvas.json); here they are
 * matched and turned into links. A code link opens the canvas on that board: the view moves to it, the board is
 * selected and its side panel shows.
 *
 * Pure functions, no React (tested in boardCode.test.ts).
 */

import { boardCodeOf } from './boardKey.ts';

/** URL parameter of a code link (`?code=APP-ONB-W2`). */
export const CODE_PARAM = 'code';

/** A code for comparisons: upper case, letters and digits only (`app onb-w2` → `APPONBW2`). */
export const normalizeBoardCode = (code: string): string => code.toUpperCase().replace(/[^A-Z0-9]/g, '');

/** The parts of a code: `APP ONB-W2` → `['APP', 'ONB', 'W2']`. */
const codeParts = (code: string): string[] => code.trim().toUpperCase().split(/[\s-]+/).filter(Boolean);

/**
 * Whether a typed code names a board on its canvas: the full code, the code without the project part, or the board
 * part alone (`APP ONB-W2`, `ONB-W2`, `w2`) — case, spaces and dashes do not matter, a fragment (`2`, `NBW2`) does not
 * match.
 */
export const boardCodeMatches = (typedCode: string, boardCode: string | null | undefined): boolean => {
  const typed = normalizeBoardCode(typedCode);
  if (!typed || !boardCode) return false;
  const parts = codeParts(boardCode);
  return parts.some((_, index) => parts.slice(index).join('') === typed);
};

/**
 * Whether a typed code belongs to a canvas: it starts with the canvas' code prefix, with or without the project part
 * (`APP ONB-W2` and `ONB-W2` belong to `APP ONB`). Used by the overview to send a pasted code to its canvas.
 */
export const codeBelongsToCanvas = (typedCode: string, canvasCode: string | null | undefined): boolean => {
  const typed = normalizeBoardCode(typedCode);
  if (!typed || !canvasCode) return false;
  const parts = codeParts(canvasCode);
  return parts.some((_, index) => {
    const prefix = parts.slice(index).join('');
    return typed.length > prefix.length && typed.startsWith(prefix);
  });
};

/** A code as a URL value: parts joined by dashes (`APP ONB-W2` → `APP-ONB-W2`), readable in a pasted link. */
export const boardCodeUrlValue = (code: string): string => codeParts(code).join('-');

/**
 * Link that opens a canvas on one board by its code: the canvas page URL (other parameters dropped) with `code`,
 * plus the look (`theme`, `mode`) when the board is shown in one.
 */
export const buildBoardCodeLink = (
  canvasPageUrl: string,
  code: string,
  themeId: string | null,
  mode: 'light' | 'dark'
): string => {
  const url = new URL(canvasPageUrl);
  url.search = '';
  url.hash = '';
  url.searchParams.set(CODE_PARAM, boardCodeUrlValue(code));
  if (themeId) {
    url.searchParams.set('theme', themeId);
    url.searchParams.set('mode', mode);
  }
  return url.toString().replace(/%3A/gi, ':');
};

/**
 * The board a typed code names on a canvas: by its code (`boardCodeMatches`), else by its file name without extension
 * (`onb-w-2-welcome`, for boards without a code). Null when none or more than one board matches.
 */
export const findBoardForCode = <Board extends { code?: string | null; boardKey?: string | null }>(
  boards: readonly Board[],
  typedCode: string
): Board | null => {
  const byCode = boards.filter(board => boardCodeMatches(typedCode, board.code));
  if (byCode.length > 0) return byCode.length === 1 ? byCode[0] : null;
  const typed = typedCode.trim().toLowerCase();
  const byName = boards.filter(board => board.boardKey && boardCodeOf(board.boardKey).toLowerCase() === typed);
  return byName.length === 1 ? byName[0] : null;
};
