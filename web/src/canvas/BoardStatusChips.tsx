'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';

import type { DesignStudioBoardView } from '../api/types';
import { useDesignStudio } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';
import { boardStatusDefinition, boardStatusLabel } from './boardStatus';

export interface BoardStatusChipsProps {
  projectKey: string;
  canvasKey: string;
  board: DesignStudioBoardView;
  /** The configured status keys in display order (`DesignStudioCanvasView.statuses`). */
  statuses: readonly string[];
  writesAllowed: boolean;
  /** Called with the saved status (the canvas updates its copy). */
  onStatusChanged: (status: string) => void;
}

/** The review status of a board as chips (one per configured status); a click saves it. */
export const BoardStatusChips = ({
  projectKey,
  canvasKey,
  board,
  statuses,
  writesAllowed,
  onStatusChanged,
}: BoardStatusChipsProps) => {
  const { api, notify, statuses: statusDefinitions } = useDesignStudio();
  const [patchStatus, { isLoading }] = api.useSetBoardStatusMutation();
  return (
    <fieldset className="m-0 flex flex-wrap items-center gap-1.5 border-0 p-0" aria-label="Board status">
      {statuses.map(status => {
        const Icon = boardStatusDefinition(statusDefinitions, status)?.icon;
        const isActive = board.status === status;
        return (
          <button
            key={status}
            type="button"
            aria-pressed={isActive}
            disabled={!writesAllowed || isLoading || isActive || !board.boardKey}
            onClick={async () => {
              try {
                await patchStatus({
                  projectKey,
                  canvasKey,
                  boardKey: board.boardKey ?? '',
                  body: { status },
                }).unwrap();
                onStatusChanged(status);
              } catch (error) {
                notify.error(normalizeDddError(error, 'Request failed').message);
              }
            }}
            className={cx(
              'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold transition-colors motion-reduce:transition-none',
              'disabled:cursor-default',
              isActive
                ? 'bg-primary text-primary-foreground'
                : 'bg-tint text-muted-foreground hover:bg-tint-strong hover:text-foreground disabled:opacity-60'
            )}
          >
            {Icon && <Icon className="size-3.5" aria-hidden />}
            {boardStatusLabel(statusDefinitions, status)}
          </button>
        );
      })}
      {board.hasChangedSinceStatus && (
        <span className="text-xs text-muted-foreground" title="The board file changed after this status was set">
          changed since
        </span>
      )}
    </fieldset>
  );
};
