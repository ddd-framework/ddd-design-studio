// Runtime import of the main entry in Node. The canvas entry imports a stylesheet and therefore needs a bundler; its
// file and its imports are checked by scripts/check-package.mjs instead.
const main = await import('@mgamadeus/ddd-design-studio');

const expected = [
  'createDesignStudioApi',
  'DesignStudioProvider',
  'DesignStudioOverviewScreen',
  'DesignStudioProjectScreen',
  'DesignStudioReviewsScreen',
  'useDesignStudio',
  'useDesignStudioTexts',
  'DEFAULT_DESIGN_STUDIO_TEXTS',
  'buildDesignReference',
  'buildBoardDeepLink',
  'cx',
];
const missing = expected.filter(name => !(name in main));
if (missing.length > 0) {
  console.error(`missing exports: ${missing.join(', ')}`);
  process.exit(1);
}
if ('DesignStudioCanvasScreen' in main) {
  console.error('the main entry must not export the canvas screen');
  process.exit(1);
}
console.log(`ok      main entry exports ${Object.keys(main).length} names`);
