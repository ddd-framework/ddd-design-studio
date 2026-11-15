'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { FileDown } from 'lucide-react';
import { useEffect, useState } from 'react';

import type { DesignStudioApi } from '../api/createDesignStudioApi';
import type { DesignStudioBoardSvgExport } from '../api/types';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { describeRasterSize, downloadSvg, formatByteSize, formatPixelShare } from './svgExportFile';
import type { BoardRegion } from './designReference';

export interface BoardSvgExportProps {
  /** The export hook of the studio's api (`useExportBoardAsSvgMutation`). */
  useExportBoardAsSvgMutation: NonNullable<DesignStudioApi['useExportBoardAsSvgMutation']>;
  projectKey: string;
  canvasKey: string;
  boardKey: string;
  /** The look the canvas shows (`<themeId>[:<mode>]`), null = the board as authored. */
  themeReference: string | null;
  /** Label of that look for the panel. */
  themeLabel: string;
  /** The region marked on the board; null = the whole board. */
  region: BoardRegion | null;
  /** The device frame of a responsive board; null = a fixed board or a free width (its first frame). */
  deviceKey: string | null;
}

/**
 * The focus view's SVG export (renderer 1.2.0): a popover that exports the board in the canvas' look — the marked
 * region only when one is marked — and shows the SVG's size next to the raster picture's, the share of differing
 * pixels and the comparison picture before the download.
 */
export const BoardSvgExport = ({
  useExportBoardAsSvgMutation,
  projectKey,
  canvasKey,
  boardKey,
  themeReference,
  themeLabel,
  region,
  deviceKey,
}: BoardSvgExportProps) => {
  const { components, notify } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const { Button, Popover, PopoverTrigger, PopoverContent } = components;
  const [exportBoardAsSvg, { isLoading }] = useExportBoardAsSvgMutation();
  const [svgExport, setSvgExport] = useState<DesignStudioBoardSvgExport | null>(null);

  // another board, look, region or frame: the shown export no longer matches
  const scopeKey = `${boardKey}|${themeReference ?? ''}|${region ? `${region.x},${region.y},${region.width},${region.height}` : ''}|${deviceKey ?? ''}`;
  useEffect(() => {
    void scopeKey;
    setSvgExport(null);
  }, [scopeKey]);

  const startExport = async () => {
    try {
      const response = await exportBoardAsSvg({
        projectKey,
        canvasKey,
        boardKey,
        body: {
          theme: themeReference,
          region: region ? { x: Math.round(region.x), y: Math.round(region.y), width: Math.round(region.width), height: Math.round(region.height) } : null,
          device: deviceKey,
        },
      }).unwrap();
      setSvgExport(response.designStudioBoardSvgExport);
    } catch (error) {
      notify.error(normalizeDddError(error, 'Export failed').message);
    }
  };

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" aria-label={texts.exportSvgTitle}>
          <FileDown />
          {texts.exportSvg}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-[min(36rem,calc(100vw-2rem))]">
        <div className="flex flex-col gap-3">
          <div>
            <p className="text-sm font-semibold">{texts.exportSvgTitle}</p>
            <p className="text-xs text-muted-foreground">{texts.exportSvgScope(themeLabel, region !== null)}</p>
          </div>
          {svgExport ? (
            <>
              {svgExport.comparisonImageDataUrl && (
                // biome-ignore lint/performance/noImgElement: a data URL of the renderer's comparison picture
                <img src={svgExport.comparisonImageDataUrl} alt="" className="max-h-[50vh] w-full rounded-md object-contain ring-1 ring-foreground/10" />
              )}
              <p className="text-xs text-muted-foreground">{texts.exportSvgLostEffects}</p>
              <p className="text-sm">
                {texts.exportSvgSizes(
                  formatByteSize(svgExport.svgByteSize ?? 0),
                  formatByteSize(svgExport.svgGzipByteSize ?? 0),
                  describeRasterSize(svgExport.rasterWebpByteSize, svgExport.rasterPngByteSize)
                )}
                <br />
                {texts.exportSvgDiffering(formatPixelShare(svgExport.differingPixelRatio ?? 0))}
              </p>
              <Button size="sm" onClick={() => svgExport.svg && downloadSvg(svgExport.svg, svgExport.fileName ?? 'board.svg')}>
                <FileDown />
                {texts.exportSvgDownload}
              </Button>
            </>
          ) : (
            <Button size="sm" disabled={isLoading} onClick={() => void startExport()}>
              {isLoading ? texts.exportSvgRunning : texts.exportSvgStart}
            </Button>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
};
