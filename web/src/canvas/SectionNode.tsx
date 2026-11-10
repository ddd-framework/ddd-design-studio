'use client';

import { type Node, type NodeProps, useStore } from '@xyflow/react';
import { memo } from 'react';

import { SECTION_PADDING } from './canvasLayout';
import { useCanvasView } from './canvasView';

export type SectionNodeData = {
  sectionId: string;
  title: string;
  boardCount: number;
  /** Overview level (far zoom): the section is drawn as a card with cover thumbnails instead of its boards. */
  isOverview: boolean;
  /** Small thumbnails of the first boards (overview card). */
  coverUrls: string[];
  /** Section width in canvas px (overview title size). */
  width: number;
};
export type SectionFlowNode = Node<SectionNodeData, 'section'>;

/** Title size in screen px on an overview card (the card scales with the canvas, the title keeps readable). */
const OVERVIEW_TITLE_SCREEN_PX = 15;

/**
 * A section of the canvas. Normally a quiet background exactly around its title and boards (computed by
 * `computeCanvasLayout`) with the title as one bold line above the boards — scaled with the canvas like the boards,
 * never behind them. At the overview level (far zoom) the boards are not drawn; the section becomes a card with its
 * title, board count and a few cover thumbnails, and a click zooms to it.
 */
const SectionNodeComponent = ({ data }: NodeProps<SectionFlowNode>) => {
  const { onZoomToSection } = useCanvasView();
  // follows the zoom only at the overview level (a handful of section cards), a constant 1 otherwise
  const zoom = useStore(state => (data.isOverview ? Math.round(state.transform[2] * 1000) / 1000 : 1));

  if (!data.isOverview) {
    return (
      <div className="pointer-events-none h-full w-full rounded-[64px] bg-card/50 ring-2 ring-foreground/5 backdrop-blur-xl">
        <div
          className="flex items-baseline gap-4 overflow-hidden text-ellipsis whitespace-nowrap"
          style={{ padding: `${SECTION_PADDING}px ${SECTION_PADDING}px 0` }}
          title={data.title}
        >
          <span className="text-[56px] leading-none font-bold tracking-tight text-foreground">{data.title}</span>
          <span className="text-[28px] leading-none text-muted-foreground">{data.boardCount} boards</span>
        </div>
      </div>
    );
  }

  // readable on screen, but never wider than the card allows
  const titleFontSize = Math.min(OVERVIEW_TITLE_SCREEN_PX / zoom, data.width / 7);
  return (
    <button
      type="button"
      onClick={() => onZoomToSection(data.sectionId)}
      aria-label={`${data.title}: ${data.boardCount} boards — zoom in`}
      className="flex h-full w-full cursor-zoom-in flex-col overflow-hidden rounded-[64px] bg-card text-left shadow-surface-raised ring-1 ring-foreground/10 transition-shadow hover:ring-[24px] hover:ring-brand/40"
      style={{ padding: titleFontSize }}
    >
      <span className="block w-full" style={{ fontSize: titleFontSize, lineHeight: 1.2 }}>
        <span className="line-clamp-2 font-bold text-foreground">{data.title}</span>
        <span className="block text-muted-foreground" style={{ fontSize: titleFontSize * 0.8 }}>
          {data.boardCount} boards
        </span>
      </span>
      <span
        className="mt-auto grid min-h-0 flex-1 grid-cols-2 content-end"
        style={{ gap: titleFontSize * 0.6, paddingTop: titleFontSize * 0.6 }}
      >
        {data.coverUrls.map(coverUrl => (
          // biome-ignore lint/performance/noImgElement: small cover thumbnails from the API at canvas scale
          <img
            key={coverUrl}
            src={coverUrl}
            alt=""
            loading="lazy"
            decoding="async"
            draggable={false}
            className="pointer-events-none w-full rounded-[24px] object-cover object-top shadow-surface-raised select-none"
          />
        ))}
      </span>
    </button>
  );
};

export const SectionNode = memo(SectionNodeComponent);
