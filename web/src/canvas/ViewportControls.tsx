'use client';

import { RotateCw, Smartphone } from 'lucide-react';
import { useRef } from 'react';

import type { DesignDevice } from '../api/types';
import { useDesignStudio } from '../provider/DesignStudioProvider';
import { type DeviceViewport, rotateViewport, viewportLabel, viewportOfDevice } from './deviceViewport';

/** Viewport controls of a responsive board (focus view, site view): device picker, rotation, width handle. */
export interface ViewportToolbarProps {
  devices: readonly DesignDevice[];
  viewport: DeviceViewport;
  /** Open pins placed in other viewport widths (not shown in this one). */
  hiddenPinCount: number;
  showsDeviceFrame: boolean;
  onShowsDeviceFrameChange: (showsDeviceFrame: boolean) => void;
  onViewportChange: (viewport: DeviceViewport) => void;
}

/** Device picker (presets), landscape / portrait, the current size and the device frame switch of a responsive board. */
export const ViewportToolbar = ({
  devices,
  viewport,
  hiddenPinCount,
  showsDeviceFrame,
  onShowsDeviceFrameChange,
  onViewportChange,
}: ViewportToolbarProps) => {
  const { components } = useDesignStudio();
  const { Button } = components;
  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-border px-5 py-2 text-sm">
      <label className="flex items-center gap-2">
        <span className="sr-only">Device</span>
        <select
          className="h-8 rounded-lg border border-border bg-card px-2 text-sm"
          value={viewport.deviceKey ?? ''}
          onChange={event => {
            const device = devices.find(candidate => candidate.deviceKey === event.target.value);
            if (device) onViewportChange(viewportOfDevice(device));
          }}
        >
          {viewport.deviceKey === null && <option value="">Free width</option>}
          {devices.map(device => (
            <option key={device.deviceKey ?? ''} value={device.deviceKey ?? ''}>
              {device.label} · {device.width} × {device.height}
            </option>
          ))}
        </select>
      </label>
      <Button
        variant="outline"
        size="sm"
        aria-label="Rotate (landscape / portrait)"
        onClick={() => onViewportChange(rotateViewport(viewport, devices))}
      >
        <RotateCw /> Rotate
      </Button>
      <span className="text-muted-foreground tabular-nums">{viewportLabel(viewport, devices)}</span>
      <Button
        variant={showsDeviceFrame ? 'default' : 'outline'}
        size="sm"
        aria-pressed={showsDeviceFrame}
        onClick={() => onShowsDeviceFrameChange(!showsDeviceFrame)}
      >
        <Smartphone /> Device frame
      </Button>
      {hiddenPinCount > 0 && (
        <span className="ml-auto text-xs text-muted-foreground">
          {hiddenPinCount} open pin(s) in other widths — pick their size in the comment list
        </span>
      )}
    </div>
  );
};

export interface WidthDragHandleProps {
  /** Scale the viewport is drawn at (screen pixels per page pixel). */
  scale: number;
  width: number;
  onWidthChange: (width: number) => void;
}

/** The handle at the right edge of the viewport: dragging it sets a free width (page pixels). */
export const WidthDragHandle = ({ scale, width, onWidthChange }: WidthDragHandleProps) => {
  const dragStartRef = useRef<{ clientX: number; width: number } | null>(null);
  return (
    <button
      type="button"
      aria-label={`Viewport width ${width} px: drag to change`}
      className="absolute top-1/2 -right-6 z-30 h-24 w-3 -translate-y-1/2 cursor-ew-resize touch-none rounded-full bg-foreground/40 hover:bg-foreground/70"
      onPointerDown={event => {
        event.currentTarget.setPointerCapture(event.pointerId);
        dragStartRef.current = { clientX: event.clientX, width };
      }}
      onPointerMove={event => {
        const dragStart = dragStartRef.current;
        if (!dragStart) return;
        onWidthChange(dragStart.width + (event.clientX - dragStart.clientX) / Math.max(0.05, scale));
      }}
      onPointerUp={() => {
        dragStartRef.current = null;
      }}
      onKeyDown={event => {
        if (event.key === 'ArrowLeft') onWidthChange(width - 10);
        if (event.key === 'ArrowRight') onWidthChange(width + 10);
      }}
    />
  );
};
