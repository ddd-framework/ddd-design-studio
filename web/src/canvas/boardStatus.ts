import type { DesignStudioStatusDefinition, DesignStudioStatuses } from '../provider/types';

/**
 * The review statuses are configured in the backend (`DesignStudioCanvasView.statuses`, the first one is the default),
 * so a board's status is a plain string here; the application's status definitions (`environment.statuses`) know the
 * usual ones, any other key shows as it is.
 */
export const boardStatusDefinition = (
  statuses: DesignStudioStatuses,
  status: string
): DesignStudioStatusDefinition | undefined => statuses[status];

export const boardStatusLabel = (statuses: DesignStudioStatuses, status: string): string =>
  boardStatusDefinition(statuses, status)?.label ?? status;
