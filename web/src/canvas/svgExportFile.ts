/**
 * Pure helpers of the focus view's SVG export (tested in svgExportFile.test.ts): sizes and shares as the panel shows
 * them, and the file a browser saves.
 */

/** `224 KB`, `1.2 MB`; at least `1 KB`. */
export const formatByteSize = (byteSize: number): string =>
  byteSize >= 1024 * 1024 ? `${(byteSize / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(byteSize / 1024))} KB`;

/** `5.3 %` (one decimal). */
export const formatPixelShare = (ratio: number): string => `${(Math.max(0, ratio) * 100).toFixed(1)} %`;

/** The raster size the panel names: the WebP when the server made one, else the PNG. */
export const describeRasterSize = (rasterWebpByteSize: number | null | undefined, rasterPngByteSize: number | undefined): string =>
  rasterWebpByteSize != null ? `WebP ${formatByteSize(rasterWebpByteSize)}` : `PNG ${formatByteSize(rasterPngByteSize ?? 0)}`;

/** Saves the SVG under its file name (a blob URL on a temporary link, released afterwards). */
export const downloadSvg = (svg: string, fileName: string): void => {
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
};
