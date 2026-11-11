'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { Hourglass, LayoutGrid, Palette } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

import type { DesignStudioProjectSummary } from '../api/types';
import { boardCodeUrlValue, CODE_PARAM, codeBelongsToCanvas } from '../canvas/boardCode';
import { useDesignStudio, useDesignStudioTexts } from '../provider/DesignStudioProvider';
import type { DesignStudioPaths } from '../provider/types';

/** Without the application's `useNavigate` the overview shows a link to the board instead of navigating. */
const useNoNavigate = (): ((href: string) => void) | null => null;

export interface DesignStudioOverviewScreenProps {
  /** Page title (a two-tone title wraps its accent part in `<em>`). */
  title?: ReactNode;
  /** One line under the title. */
  description?: ReactNode;
}

/**
 * The canvas page that opens a board code (`APP ONB-W2`, `ONB-W2`): the path of the one canvas whose code the
 * typed code starts with, with `?code=`; null when no canvas or more than one matches.
 */
const codeTarget = (
  projects: readonly DesignStudioProjectSummary[],
  typedCode: string,
  paths: DesignStudioPaths
): string | null => {
  const targets = projects.flatMap(project =>
    (project.canvases?.elements ?? [])
      .filter(canvas => codeBelongsToCanvas(typedCode, canvas.code))
      .map(canvas => `${paths.canvas(project.projectKey ?? '', canvas.canvasKey ?? '')}?${CODE_PARAM}=${encodeURIComponent(boardCodeUrlValue(typedCode))}`)
  );
  return targets.length === 1 ? targets[0] : null;
};

/**
 * Overview: every design project with its canvases (boards, open threads, decisions per canvas), and a field that
 * opens a board by its code. `?code=<board code>` on this page opens it directly (a short link that needs no canvas
 * path).
 */
export const DesignStudioOverviewScreen = ({
  title = (
    <>
      Design <em>Studio</em>
    </>
  ),
  description = 'Design projects and their canvases — review, comment, decide.',
}: DesignStudioOverviewScreenProps) => {
  const { api, components, paths, router } = useDesignStudio();
  const texts = useDesignStudioTexts();
  const {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardGrid,
    PageHeader,
    PageSections,
    SearchField,
    Skeleton,
    StatusCallout,
  } = components;
  const { Link } = router;
  const navigate = (router.useNavigate ?? useNoNavigate)();
  const { data, isLoading, error } = api.useOverviewQuery();
  const overview = data?.designStudioOverview;
  const projects = useMemo(() => overview?.projects?.elements ?? [], [overview]);
  const expiringThreadCount = overview?.expiringThreadCount ?? 0;
  const hasCodes = projects.some(project => (project.canvases?.elements ?? []).some(canvas => canvas.code));

  // Open a board by its code: typed into the field, or `?code=` on this page.
  const codeParam = router.useSearchParams().get(CODE_PARAM);
  const [typedCode, setTypedCode] = useState('');
  const [pendingCode, setPendingCode] = useState<{ code: string; href: string | null } | null>(null);
  const openCode = (code: string) => {
    if (!code.trim()) return;
    const href = codeTarget(projects, code, paths);
    setPendingCode({ code: code.trim(), href });
    if (href && navigate) navigate(href);
  };
  const openCodeRef = useRef(openCode);
  openCodeRef.current = openCode;
  const handledCodeParamRef = useRef<string | null>(null);
  useEffect(() => {
    if (!codeParam || !overview || handledCodeParamRef.current === codeParam) return;
    handledCodeParamRef.current = codeParam;
    openCodeRef.current(codeParam);
  }, [codeParam, overview]);
  const submitCode = (event: FormEvent) => {
    event.preventDefault();
    openCode(typedCode);
  };

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
        {hasCodes && (
          <form onSubmit={submitCode} className="flex max-w-md items-center gap-2">
            <SearchField
              value={typedCode}
              onChange={setTypedCode}
              placeholder="Open a board by its code, e.g. APP ONB-W2"
              aria-label="Board code"
              className="flex-1"
              clearable
            />
            <Button type="submit" variant="outline" disabled={!typedCode.trim()}>
              Open
            </Button>
          </form>
        )}
        {pendingCode && !pendingCode.href && (
          <StatusCallout tone="destructive" title={texts.boardCodeNotFound(pendingCode.code)} />
        )}
        {pendingCode?.href && !navigate && (
          <StatusCallout
            title={`Board ${pendingCode.code}`}
            description={<Link href={pendingCode.href}>Open it on its canvas</Link>}
          />
        )}
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
                          {canvas.code && (
                            <span className="shrink-0 text-xs font-semibold text-muted-foreground tabular-nums">{canvas.code}</span>
                          )}
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
