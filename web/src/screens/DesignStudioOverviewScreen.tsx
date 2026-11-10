'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { Hourglass, LayoutGrid, Palette } from 'lucide-react';
import type { ReactNode } from 'react';

import { useDesignStudio } from '../provider/DesignStudioProvider';

export interface DesignStudioOverviewScreenProps {
  /** Page title (a two-tone title wraps its accent part in `<em>`). */
  title?: ReactNode;
  /** One line under the title. */
  description?: ReactNode;
}

/** Overview: every design project with its canvases (boards, open threads, decisions per canvas). */
export const DesignStudioOverviewScreen = ({
  title = (
    <>
      Design <em>Studio</em>
    </>
  ),
  description = 'Design projects and their canvases — review, comment, decide.',
}: DesignStudioOverviewScreenProps) => {
  const { api, components, paths, router } = useDesignStudio();
  const { Badge, Card, CardContent, CardHeader, CardTitle, CardGrid, PageHeader, PageSections, Skeleton, StatusCallout } =
    components;
  const { Link } = router;
  const { data, isLoading, error } = api.useOverviewQuery();
  const overview = data?.designStudioOverview;
  const projects = overview?.projects?.elements ?? [];
  const expiringThreadCount = overview?.expiringThreadCount ?? 0;

  return (
    <>
      <PageHeader
        title={title}
        description={description}
        leading={<Palette className="size-7 text-brand-emphasis" aria-hidden />}
      />
      {error && (
        <StatusCallout
          tone="destructive"
          title="The overview could not be loaded"
          description={normalizeDddError(error, 'Request failed').message}
          className="mb-6"
        />
      )}
      <PageSections>
        {isLoading && <Skeleton className="h-40 w-full rounded-2xl" />}
        {!isLoading && !error && projects.length === 0 && (
          <StatusCallout
            title="No design projects configured"
            description="Projects and canvases are configured in the backend; their boards are read from the canvas folders in the checkout."
          />
        )}
        {projects.length > 0 && (
          <CardGrid>
            {projects.map(project => {
              const projectKey = project.projectKey ?? '';
              const canvases = project.canvases?.elements ?? [];
              return (
                <Card key={projectKey}>
                  <CardHeader>
                    <CardTitle className="flex items-center justify-between gap-2 text-title-card">
                      <Link href={paths.project(projectKey)} className="hover:underline">
                        {project.label ?? projectKey}
                      </Link>
                      <Badge variant="outline">
                        {canvases.length} {canvases.length === 1 ? 'canvas' : 'canvases'}
                      </Badge>
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {canvases.length === 0 && (
                      <p className="text-sm text-muted-foreground">No canvas configured for this project yet.</p>
                    )}
                    {canvases.map(canvas => (
                      <Link
                        key={canvas.canvasKey}
                        href={paths.canvas(projectKey, canvas.canvasKey ?? '')}
                        className="flex items-center justify-between gap-3 rounded-xl bg-tint px-3 py-2 text-sm transition-colors hover:bg-tint-strong"
                      >
                        <span className="flex min-w-0 items-center gap-2 font-medium">
                          <LayoutGrid className="size-4 shrink-0 text-brand-emphasis" aria-hidden />
                          <span className="truncate">{canvas.label ?? canvas.canvasKey}</span>
                        </span>
                        <span className="shrink-0 text-muted-foreground">
                          {canvas.boardCount ?? 0} boards · {canvas.openThreadCount ?? 0} open ·{' '}
                          {canvas.decisionCount ?? 0} decisions
                        </span>
                      </Link>
                    ))}
                  </CardContent>
                </Card>
              );
            })}
          </CardGrid>
        )}
        {overview && (
          <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
            <span>Boards are read from the canvas folders in the checkout; open threads and decisions are counted per canvas.</span>
            {expiringThreadCount > 0 && (
              <span className="inline-flex items-center gap-1 font-medium text-foreground">
                <Hourglass className="size-3.5" aria-hidden />
                {expiringThreadCount} {expiringThreadCount === 1 ? 'thread expires' : 'threads expire'} soon (threads idle
                for {overview.threadIdleDays ?? 0} days are deleted) — open the board and choose “Keep”.
              </span>
            )}
          </p>
        )}
      </PageSections>
    </>
  );
};
