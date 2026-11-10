import type {
  Api,
  BaseQueryFn,
  coreModuleName,
  FetchArgs,
  FetchBaseQueryError,
  FetchBaseQueryMeta,
} from '@reduxjs/toolkit/query';
import type { reactHooksModuleName, TypedUseMutation, TypedUseQuery } from '@reduxjs/toolkit/query/react';

import type {
  DesignStudioBoardStatusPatchArg,
  DesignStudioBoardStatusResponse,
  DesignStudioCanvasArg,
  DesignStudioCanvasCommentsArg,
  DesignStudioCanvasResponse,
  DesignStudioCanvasRevisionResponse,
  DesignStudioCommentArg,
  DesignStudioCommentCreateArg,
  DesignStudioCommentPatchArg,
  DesignStudioCommentReplyArg,
  DesignStudioCommentResolveArg,
  DesignStudioCommentResponse,
  DesignStudioCommentsResponse,
  DesignStudioDeleteResponse,
  DesignStudioOverviewResponse,
  DesignStudioThemesArg,
  DesignStudioThemesResponse,
  DesignStudioThumbnailRenderRequestArg,
} from './types';

/** The one cache tag of the studio: every query provides it, every mutation invalidates it. */
export const DESIGN_STUDIO_TAG = 'DesignStudio';

/** The base query the studio's endpoints run on: a fetch-style one (`createDddBaseQuery` of `@mgamadeus/ddd-web/api`). */
export type DesignStudioBaseQuery = BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError,
  object,
  FetchBaseQueryMeta
>;

/** The application's RTK Query api (`createApi` from `@reduxjs/toolkit/query/react`) the endpoints are injected into. */
export type DesignStudioBaseApi<ReducerPath extends string = string, TagTypes extends string = string> = Api<
  DesignStudioBaseQuery,
  // biome-ignore lint/complexity/noBannedTypes: RTK Query's own type of "no endpoints yet" (`createApi({ endpoints: () => ({}) })`)
  {},
  ReducerPath,
  TagTypes,
  typeof coreModuleName | typeof reactHooksModuleName
>;

export interface CreateDesignStudioApiOptions<ReducerPath extends string, TagTypes extends string> {
  /** The application's api; its base query carries the origin, the session and the refresh. */
  baseApi: DesignStudioBaseApi<ReducerPath, TagTypes>;
  /** Path prefix of the studio's routes, without a trailing slash (e.g. `/api/admin/designStudio`). */
  apiPrefix: string;
}

type DesignStudioQueryHook<ResultType, QueryArg> = TypedUseQuery<ResultType, QueryArg, DesignStudioBaseQuery>;
type DesignStudioMutationHook<ResultType, QueryArg> = TypedUseMutation<ResultType, QueryArg, DesignStudioBaseQuery>;

/** The hooks `createDesignStudioApi` returns (`environment.api`). */
export interface DesignStudioApi {
  useOverviewQuery: DesignStudioQueryHook<DesignStudioOverviewResponse, void>;
  useThemesQuery: DesignStudioQueryHook<DesignStudioThemesResponse, DesignStudioThemesArg>;
  useCanvasQuery: DesignStudioQueryHook<DesignStudioCanvasResponse, DesignStudioCanvasArg>;
  useCanvasRevisionQuery: DesignStudioQueryHook<DesignStudioCanvasRevisionResponse, DesignStudioCanvasArg>;
  useCanvasCommentsQuery: DesignStudioQueryHook<DesignStudioCommentsResponse, DesignStudioCanvasCommentsArg>;
  useExpiringCommentsQuery: DesignStudioQueryHook<DesignStudioCommentsResponse, void>;
  useRequestThumbnailRenderingMutation: DesignStudioMutationHook<
    DesignStudioCanvasResponse,
    DesignStudioThumbnailRenderRequestArg
  >;
  useSetBoardStatusMutation: DesignStudioMutationHook<DesignStudioBoardStatusResponse, DesignStudioBoardStatusPatchArg>;
  useCreateCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentCreateArg>;
  useReplyToCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentReplyArg>;
  usePatchCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentPatchArg>;
  useDeleteCommentMutation: DesignStudioMutationHook<DesignStudioDeleteResponse, DesignStudioCommentArg>;
  useResolveCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentResolveArg>;
  useReopenCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentArg>;
  useKeepCommentMutation: DesignStudioMutationHook<DesignStudioCommentResponse, DesignStudioCommentArg>;
}

const PROVIDED = [{ type: DESIGN_STUDIO_TAG, id: 'LIST' }] as const;
const INVALIDATED = [{ type: DESIGN_STUDIO_TAG, id: 'LIST' }, { type: DESIGN_STUDIO_TAG }] as const;

/**
 * Injects the studio's 15 operations into the application's RTK Query api and returns their hooks. Call it once (module
 * scope) and hand the result to `DesignStudioProvider` as `environment.api`.
 *
 * Cache: one tag (`DesignStudio`). Every query provides it and every mutation invalidates it, so a write refetches
 * whatever the studio has on screen (the canvas, its comments, the overview).
 */
export const createDesignStudioApi = <ReducerPath extends string, TagTypes extends string>({
  baseApi,
  apiPrefix,
}: CreateDesignStudioApiOptions<ReducerPath, TagTypes>): DesignStudioApi => {
  const canvasUrl = (arg: { projectKey: string; canvasKey: string }) =>
    `${apiPrefix}/canvases/${arg.projectKey}/${arg.canvasKey}`;
  const commentUrl = (arg: DesignStudioCommentArg) => `${apiPrefix}/comments/${arg.designStudioCommentId}`;

  // Widened to plain strings: the returned hooks do not depend on the application's reducer path and tag names. An api
  // is invariant in both, hence the cast; it also keeps the hook types nameable in the package's declaration files.
  const widenedBaseApi = baseApi as unknown as DesignStudioBaseApi;
  const api = widenedBaseApi.enhanceEndpoints({ addTagTypes: [DESIGN_STUDIO_TAG] }).injectEndpoints({
    endpoints: build => ({
      designStudioOverview: build.query<DesignStudioOverviewResponse, void>({
        query: () => ({ url: `${apiPrefix}/overview` }),
        providesTags: PROVIDED,
      }),
      designStudioThemes: build.query<DesignStudioThemesResponse, DesignStudioThemesArg>({
        query: arg => ({
          url: `${apiPrefix}/themes`,
          params: { search: arg.search, noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        providesTags: PROVIDED,
      }),
      designStudioCanvas: build.query<DesignStudioCanvasResponse, DesignStudioCanvasArg>({
        query: arg => ({
          url: canvasUrl(arg),
          params: { theme: arg.theme, noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        providesTags: PROVIDED,
      }),
      designStudioCanvasRevision: build.query<DesignStudioCanvasRevisionResponse, DesignStudioCanvasArg>({
        query: arg => ({
          url: `${canvasUrl(arg)}/revision`,
          params: { theme: arg.theme, noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        providesTags: PROVIDED,
      }),
      designStudioCanvasComments: build.query<DesignStudioCommentsResponse, DesignStudioCanvasCommentsArg>({
        query: arg => ({
          url: `${canvasUrl(arg)}/comments`,
          params: {
            theme: arg.theme,
            noCache: arg.noCache,
            outputFormat: arg.outputFormat,
            onlyOpenThreads: arg.onlyOpenThreads,
            sinceId: arg.sinceId,
          },
        }),
        providesTags: PROVIDED,
      }),
      designStudioExpiringComments: build.query<DesignStudioCommentsResponse, void>({
        query: () => ({ url: `${apiPrefix}/comments/expiring` }),
        providesTags: PROVIDED,
      }),
      designStudioRequestThumbnailRendering: build.mutation<
        DesignStudioCanvasResponse,
        DesignStudioThumbnailRenderRequestArg
      >({
        query: arg => ({
          url: `${canvasUrl(arg)}/thumbnailRenderRequests`,
          method: 'POST',
          body: arg.body,
          params: { theme: arg.theme, noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioSetBoardStatus: build.mutation<DesignStudioBoardStatusResponse, DesignStudioBoardStatusPatchArg>({
        query: arg => ({
          url: `${canvasUrl(arg)}/boards/${arg.boardKey}/status`,
          method: 'PATCH',
          body: arg.body,
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioCreateComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentCreateArg>({
        query: arg => ({
          url: `${canvasUrl(arg)}/boards/${arg.boardKey}/comments`,
          method: 'POST',
          body: arg.body,
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioReplyToComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentReplyArg>({
        query: arg => ({
          url: `${commentUrl(arg)}/replies`,
          method: 'POST',
          body: arg.body,
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioPatchComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentPatchArg>({
        query: arg => ({
          url: commentUrl(arg),
          method: 'PATCH',
          body: arg.body,
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioDeleteComment: build.mutation<DesignStudioDeleteResponse, DesignStudioCommentArg>({
        query: arg => ({
          url: commentUrl(arg),
          method: 'DELETE',
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioResolveComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentResolveArg>({
        query: arg => ({
          url: `${commentUrl(arg)}/resolve`,
          method: 'POST',
          body: arg.body,
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioReopenComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentArg>({
        query: arg => ({
          url: `${commentUrl(arg)}/reopen`,
          method: 'POST',
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
      designStudioKeepComment: build.mutation<DesignStudioCommentResponse, DesignStudioCommentArg>({
        query: arg => ({
          url: `${commentUrl(arg)}/keep`,
          method: 'POST',
          params: { noCache: arg.noCache, outputFormat: arg.outputFormat },
        }),
        invalidatesTags: INVALIDATED,
      }),
    }),
    overrideExisting: false,
  });

  return {
    useOverviewQuery: api.useDesignStudioOverviewQuery,
    useThemesQuery: api.useDesignStudioThemesQuery,
    useCanvasQuery: api.useDesignStudioCanvasQuery,
    useCanvasRevisionQuery: api.useDesignStudioCanvasRevisionQuery,
    useCanvasCommentsQuery: api.useDesignStudioCanvasCommentsQuery,
    useExpiringCommentsQuery: api.useDesignStudioExpiringCommentsQuery,
    useRequestThumbnailRenderingMutation: api.useDesignStudioRequestThumbnailRenderingMutation,
    useSetBoardStatusMutation: api.useDesignStudioSetBoardStatusMutation,
    useCreateCommentMutation: api.useDesignStudioCreateCommentMutation,
    useReplyToCommentMutation: api.useDesignStudioReplyToCommentMutation,
    usePatchCommentMutation: api.useDesignStudioPatchCommentMutation,
    useDeleteCommentMutation: api.useDesignStudioDeleteCommentMutation,
    useResolveCommentMutation: api.useDesignStudioResolveCommentMutation,
    useReopenCommentMutation: api.useDesignStudioReopenCommentMutation,
    useKeepCommentMutation: api.useDesignStudioKeepCommentMutation,
  };
};
