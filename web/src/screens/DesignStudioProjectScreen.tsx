'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { Flag, LayoutGrid, MessageSquare } from 'lucide-react';

import { useDesignStudio } from '../provider/DesignStudioProvider';

export interface DesignStudioProjectScreenProps {
  projectKey: string;
}

/** One design project: its canvases as cards (projects and canvases come from the studio's overview). */
export const DesignStudioProjectScreen = ({ projectKey }: DesignStudioProjectScreenProps) => {
  const { api, components, paths, router } = useDesignStudio();
  const { Card, CardContent, PageHeader, Skeleton, StatusCallout } = components;
  const { Link } = router;
  const { data, isLoading, error } = api.useOverviewQuery();
  const project = data?.designStudioOverview.projects?.elements?.find(item => item.projectKey === projectKey);
  const canvases = project?.canvases?.elements ?? [];
  const isLoaded = !isLoading && !error;

  return (
    <>
      <PageHeader
        title={project?.label ?? projectKey}
        description={project ? 'The canvases of this design project.' : undefined}
      />
      {error && (
        <StatusCallout
          tone="destructive"
          title="The canvases could not be loaded"
          description={normalizeDddError(error, 'Request failed').message}
        />
      )}
      {isLoading && <Skeleton className="h-32 w-full rounded-2xl" />}
      {isLoaded && !project && (
        <StatusCallout
          title="No design project with this key is configured"
          description={
            <>
              The design studio knows no project <code className="font-mono text-xs">{projectKey}</code>. Projects and
              their canvases are configured in the backend and read from the checkout folder.
            </>
          }
        />
      )}
      {isLoaded && project && canvases.length === 0 && (
        <StatusCallout
          title="No canvases yet"
          description="Canvases are configured per project and their boards are read from the canvas folder in the checkout; a configured canvas appears here."
        />
      )}
      {canvases.length > 0 && (
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
          {canvases.map(canvas => (
            <Link key={canvas.canvasKey} href={paths.canvas(projectKey, canvas.canvasKey ?? '')} className="group">
              <Card className="h-full gap-0 py-0 transition-shadow group-hover:shadow-md">
                <CardContent className="flex h-full flex-col gap-2 p-4">
                  <span className="flex items-center gap-2 font-semibold">
                    <LayoutGrid className="size-4 shrink-0 text-brand-emphasis" aria-hidden />
                    <span className="truncate">{canvas.label ?? canvas.canvasKey}</span>
                  </span>
                  <p className="text-sm text-muted-foreground">
                    {canvas.boardCount ?? 0} boards
                    <span className="inline-flex items-center gap-1 pl-3">
                      <MessageSquare className="size-3.5" aria-hidden /> {canvas.openThreadCount ?? 0} open
                    </span>
                    <span className="inline-flex items-center gap-1 pl-3">
                      <Flag className="size-3.5" aria-hidden /> {canvas.decisionCount ?? 0} decisions
                    </span>
                  </p>
                  <p className="mt-auto text-xs text-muted-foreground">{canvas.canvasKey}</p>
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </>
  );
};
