/**
 * A board's code: its key (the file name in the canvas folder) without the `.html` extension and without an optional
 * `.dc` before it (`welcome.html` and `welcome.dc.html` both give `welcome`). Shown under the title and used as the
 * base name of downloads. A key without `.html` is returned as it is.
 */
export const boardCodeOf = (boardKey: string): string => boardKey.replace(/(?:\.dc)?\.html$/, '');
