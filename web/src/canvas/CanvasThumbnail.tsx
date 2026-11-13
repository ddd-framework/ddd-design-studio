'use client';

import { useState } from 'react';

import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { type BoardThumbnailVariant, thumbnailUrlForVariant } from './boardDetail';
import { withoutContentToken } from './canvasLiveUpdate';
import { useStableContentUrl } from './stableContentUrl';
import { useImageRetry } from './useCheckedContentUrl';

/** Whether two delivery URLs show the same content (they differ at most in the content token). */
const isSameContent = (first: string | null | undefined, second: string | null | undefined): boolean =>
  Boolean(first && second) && withoutContentToken(first) === withoutContentToken(second);

export interface CanvasThumbnailProps {
  /** The thumbnail URLs of a board or a device frame (paths of the canvas view). */
  urls: { thumbnailUrl?: string | null; thumbnailPreviewUrl?: string | null; thumbnailSmallUrl?: string | null };
  variant: BoardThumbnailVariant;
  /** Shown with the loading note until the first picture arrived. */
  title: string;
  /** Classes of the picture (position and fit inside its card). */
  imageClassName: string;
  /** No text on the placeholder (far zoom). */
  isCompact: boolean;
}

/**
 * The picture of a board (or a device frame) on the canvas in the variant of the zoom (small → preview → full). The
 * picture shown last stays underneath until the next variant has loaded, so a zoom never flashes white; before the
 * first picture arrives the card shows the title and a loading note on a light surface.
 */
export const CanvasThumbnail = ({ urls, variant, title, imageClassName, isCompact }: CanvasThumbnailProps) => {
  const { apiBaseUrl } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const path = thumbnailUrlForVariant(urls, variant);
  const latestUrl = path ? `${apiBaseUrl}${path}` : undefined;
  const [loadedUrl, setLoadedUrl] = useState<string | null>(null);
  // same content → same URL for the page's lifetime (a rotated content token never reloads the picture)
  const url = useStableContentUrl(latestUrl, isSameContent(loadedUrl, latestUrl));
  const retry = useImageRetry(url);
  if (!url) return null;
  const isLoaded = isSameContent(loadedUrl, url);
  return (
    <>
      {!loadedUrl && (
        <span
          className={cx(
            'pointer-events-none absolute inset-0 flex flex-col items-center justify-start gap-2 bg-tint px-6 pt-16 text-center text-muted-foreground',
            'animate-pulse motion-reduce:animate-none'
          )}
        >
          {!isCompact && (
            <>
              <span className="line-clamp-2 text-[19px] font-semibold">{title}</span>
              <span className="text-[16px]">{texts.thumbnailLoading}</span>
            </>
          )}
        </span>
      )}
      {loadedUrl && !isLoaded && (
        // biome-ignore lint/performance/noImgElement: the picture shown before, from the browser cache, under the next one
        <img src={loadedUrl} alt="" draggable={false} className={cx('pointer-events-none select-none', imageClassName)} />
      )}
      {/* biome-ignore lint/performance/noImgElement: canvas thumbnails are API images at canvas scale */}
      <img
        key={retry.imageKey}
        src={url}
        alt=""
        loading="lazy"
        decoding="async"
        draggable={false}
        onError={retry.onError}
        onLoad={() => setLoadedUrl(url)}
        className={cx('pointer-events-none select-none', imageClassName, (retry.isFailed || !isLoaded) && 'opacity-0')}
      />
    </>
  );
};
