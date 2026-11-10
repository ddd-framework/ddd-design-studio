'use client';

import { useEffect, useState } from 'react';

/**
 * Spreads mass mounts over frames: a component that uses this hook renders its cheap placeholder first and its full
 * content once its turn comes — at most MOUNTS_PER_FRAME per animation frame, in the order they asked. When a zoom
 * crosses a level and React Flow mounts a hundred board nodes at once, the work lands in many short frames instead of
 * one long task.
 */
const MOUNTS_PER_FRAME = 12;

const queue: Array<() => void> = [];
let isScheduled = false;

const flush = () => {
  isScheduled = false;
  for (const mount of queue.splice(0, MOUNTS_PER_FRAME)) mount();
  if (queue.length > 0) schedule();
};

const schedule = () => {
  if (isScheduled) return;
  isScheduled = true;
  requestAnimationFrame(flush);
};

/** @returns false until this component's turn, then true (stays true for its lifetime). */
export const useDeferredMount = (): boolean => {
  const [isMounted, setIsMounted] = useState(false);
  useEffect(() => {
    let isActive = true;
    const mount = () => {
      if (isActive) setIsMounted(true);
    };
    queue.push(mount);
    schedule();
    return () => {
      isActive = false;
      const index = queue.indexOf(mount);
      if (index >= 0) queue.splice(index, 1);
    };
  }, []);
  return isMounted;
};
