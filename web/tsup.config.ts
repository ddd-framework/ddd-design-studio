import { defineConfig } from 'tsup';

// Two entries built together, so what both use (the provider's context, the API types) lands in shared chunks and
// exists once: `.` (everything except the canvas screen) and `./canvas` (the canvas screen, the only code that imports
// `@xyflow/react` and its stylesheet). Dependencies and peer dependencies stay external, the stylesheet import included.
// `scripts/check-package.mjs` verifies the split after every build.
export default defineConfig({
  entry: {
    index: 'src/index.ts',
    'canvas/index': 'src/canvas/index.ts',
  },
  format: ['esm'],
  dts: true,
  sourcemap: true,
  splitting: true,
  clean: false,
  target: 'es2022',
  platform: 'browser',
  // Class names must stay readable string literals: Tailwind scans `dist` for them.
  minify: false,
  // The studio is client code (context, hooks, DOM). Bundling drops the sources' own directives, so every output
  // file gets one.
  banner: { js: "'use client';" },
});
