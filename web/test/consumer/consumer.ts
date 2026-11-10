// A strict TypeScript consumer of both entries: compiles only if the published declarations are complete.
import {
  buildDesignReference,
  createDesignStudioApi,
  DEFAULT_DESIGN_STUDIO_TEXTS,
  type DesignStudioApi,
  type DesignStudioEnvironment,
  DesignStudioOverviewScreen,
  DesignStudioProjectScreen,
  DesignStudioProvider,
  DesignStudioReviewsScreen,
  type DesignStudioTexts,
} from '@mgamadeus/ddd-design-studio';
import { DesignStudioCanvasScreen, type DesignStudioCanvasScreenProps } from '@mgamadeus/ddd-design-studio/canvas';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const baseApi = createApi({
  reducerPath: 'api',
  baseQuery: fetchBaseQuery({ baseUrl: '' }),
  tagTypes: ['Other'],
  endpoints: () => ({}),
});

export const designStudioApi: DesignStudioApi = createDesignStudioApi({ baseApi, apiPrefix: '/api/admin/designStudio' });

export const texts: Partial<DesignStudioTexts> = {
  reload: DEFAULT_DESIGN_STUDIO_TEXTS.reload,
  boardsUpdated: count => `${count}`,
};

export const environmentPart: Pick<DesignStudioEnvironment, 'api' | 'apiBaseUrl' | 'texts'> = {
  api: designStudioApi,
  apiBaseUrl: '',
  texts,
};

export const canvasProps: DesignStudioCanvasScreenProps = { projectKey: 'app', canvasKey: 'onboarding' };

export const reference: string = buildDesignReference({
  projectName: 'App',
  canvasName: 'Onboarding',
  projectKey: 'app',
  canvasKey: 'onboarding',
  boardTitle: 'Welcome',
  boardKey: 'welcome.html',
  contentHash: null,
  themeReference: null,
  url: 'https://example.test/design/app/canvases/onboarding?board=welcome.html',
});

export const components = [
  DesignStudioProvider,
  DesignStudioOverviewScreen,
  DesignStudioProjectScreen,
  DesignStudioReviewsScreen,
  DesignStudioCanvasScreen,
];
