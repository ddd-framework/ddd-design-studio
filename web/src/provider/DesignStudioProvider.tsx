'use client';

import { createContext, type ReactNode, useContext, useMemo } from 'react';

import type { DesignStudioApi } from '../api/createDesignStudioApi';
import { resolveDesignStudioTexts } from './texts';
import type {
  DesignStudioComponents,
  DesignStudioEnvironment,
  DesignStudioNotify,
  DesignStudioPaths,
  DesignStudioRouter,
  DesignStudioTexts,
} from './types';

const DesignStudioContext = createContext<DesignStudioEnvironment | null>(null);

export interface DesignStudioProviderProps {
  /** Keep it referentially stable (a module constant): every studio component reads it. */
  environment: DesignStudioEnvironment;
  children: ReactNode;
}

/** Hands the application's environment (API, router, UI primitives, …) to the studio's screens below it. */
export const DesignStudioProvider = ({ environment, children }: DesignStudioProviderProps) => (
  <DesignStudioContext.Provider value={environment}>{children}</DesignStudioContext.Provider>
);

/** The whole environment. */
export const useDesignStudio = (): DesignStudioEnvironment => {
  const environment = useContext(DesignStudioContext);
  if (!environment) {
    throw new Error('The design studio needs a DesignStudioProvider above it');
  }
  return environment;
};

export const useDesignStudioApi = (): DesignStudioApi => useDesignStudio().api;

export const useDesignStudioComponents = (): DesignStudioComponents => useDesignStudio().components;

export const useDesignStudioRouter = (): DesignStudioRouter => useDesignStudio().router;

export const useDesignStudioPaths = (): DesignStudioPaths => useDesignStudio().paths;

export const useDesignStudioNotify = (): DesignStudioNotify => useDesignStudio().notify;

/** The UI texts: the application's replacements (`environment.texts`) on top of the English defaults. Stable per environment. */
export const useDesignStudioTexts = (): DesignStudioTexts => {
  const { texts } = useDesignStudio();
  return useMemo(() => resolveDesignStudioTexts(texts), [texts]);
};
