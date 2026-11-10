#!/usr/bin/env node
// Checks a package directory (this directory after `pnpm build`, or an extracted tarball's `package/`):
//   1. every `exports` target exists
//   2. the main entry (`.`) does not reach `@xyflow/react` through its static imports
//   3. the canvas entry (`./canvas`) imports `@xyflow/react` and its stylesheet, and is listed in `sideEffects`
//   4. every JavaScript file starts with the 'use client' directive
//   5. no test file is in `dist`
//
// Usage: node scripts/check-package.mjs <packageDir>
import * as fs from 'node:fs';
import * as path from 'node:path';

const packageDir = path.resolve(process.argv[2] ?? '.');
const manifest = JSON.parse(fs.readFileSync(path.join(packageDir, 'package.json'), 'utf-8'));
const problems = [];

/** Every string leaf of an `exports` value (conditions and subpaths flattened). */
const targets = value =>
  typeof value === 'string' ? [value] : value && typeof value === 'object' ? Object.values(value).flatMap(targets) : [];

for (const target of targets(manifest.exports ?? {})) {
  const exists = fs.existsSync(path.join(packageDir, target));
  console.log(`${exists ? 'ok     ' : 'MISSING'} exports ${target}`);
  if (!exists) problems.push(`missing exports target ${target}`);
}

const IMPORT_PATTERN = /(?:^|[\s;])(?:import|export)\s*(?:[^'"()]*?\sfrom\s*)?['"]([^'"]+)['"]/g;

/** Specifiers of the static imports and re-exports of a built file. */
const staticSpecifiers = file =>
  [...fs.readFileSync(file, 'utf-8').matchAll(IMPORT_PATTERN)].map(match => match[1]);

/** Everything a built entry loads statically: its own files (followed) and the package specifiers it names. */
const loadGraph = entry => {
  const files = new Set();
  const packages = new Set();
  const queue = [path.join(packageDir, entry)];
  while (queue.length > 0) {
    const file = queue.pop();
    if (files.has(file) || !fs.existsSync(file)) continue;
    files.add(file);
    for (const specifier of staticSpecifiers(file)) {
      if (specifier.startsWith('.')) queue.push(path.resolve(path.dirname(file), specifier));
      else packages.add(specifier);
    }
  }
  return { files, packages };
};

const mainEntry = manifest.exports?.['.']?.import;
const canvasEntry = manifest.exports?.['./canvas']?.import;
if (mainEntry && canvasEntry && fs.existsSync(path.join(packageDir, mainEntry))) {
  const main = loadGraph(mainEntry);
  const canvas = loadGraph(canvasEntry);
  const mainCanvasImports = [...main.packages].filter(specifier => specifier.startsWith('@xyflow/'));
  console.log(`${mainCanvasImports.length === 0 ? 'ok     ' : 'FAILED '} main entry is free of @xyflow/react`);
  if (mainCanvasImports.length > 0) problems.push(`the main entry loads ${mainCanvasImports.join(', ')}`);

  for (const specifier of ['@xyflow/react', '@xyflow/react/dist/style.css']) {
    const isLoaded = canvas.packages.has(specifier);
    console.log(`${isLoaded ? 'ok     ' : 'MISSING'} canvas entry imports ${specifier}`);
    if (!isLoaded) problems.push(`the canvas entry does not import ${specifier}`);
  }

  // the file that carries the stylesheet import must be kept by bundlers that honour `sideEffects`
  const sideEffects = Array.isArray(manifest.sideEffects) ? manifest.sideEffects : [];
  for (const file of canvas.files) {
    if (!staticSpecifiers(file).some(specifier => specifier.endsWith('.css'))) continue;
    const relative = `./${path.relative(packageDir, file).split(path.sep).join('/')}`;
    const isListed = sideEffects.includes(relative);
    console.log(`${isListed ? 'ok     ' : 'MISSING'} sideEffects lists ${relative}`);
    if (!isListed) problems.push(`${relative} imports a stylesheet but is not in sideEffects`);
  }
}

const distDir = path.join(packageDir, 'dist');
if (fs.existsSync(distDir)) {
  const distFiles = fs.readdirSync(distDir, { recursive: true }).map(String);
  const withoutDirective = distFiles.filter(
    file => file.endsWith('.js') && !/^['"]use client['"];/.test(fs.readFileSync(path.join(distDir, file), 'utf-8'))
  );
  console.log(`${withoutDirective.length === 0 ? 'ok     ' : 'FAILED '} every .js file starts with 'use client'`);
  if (withoutDirective.length > 0) problems.push(`no 'use client' in ${withoutDirective.join(', ')}`);
  const testFiles = distFiles.filter(file => /\.test\./.test(file));
  console.log(`${testFiles.length === 0 ? 'ok     ' : 'FAILED '} no test files in dist`);
  if (testFiles.length > 0) problems.push(`test files in dist: ${testFiles.join(', ')}`);
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`${packageDir}: ${problem}`);
  process.exit(1);
}
