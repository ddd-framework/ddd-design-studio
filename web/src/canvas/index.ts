/**
 * The canvas entry of the design studio (published as `@mgamadeus/ddd-design-studio/canvas`).
 *
 * The canvas screen pulls in the canvas engine (`@xyflow/react` and its stylesheet) and measures the DOM: load it
 * client-only and lazily (e.g. a dynamic import without server rendering), not from a server-rendered module.
 */
export { DesignStudioCanvasScreen, type DesignStudioCanvasScreenProps } from './DesignStudioCanvasScreen';
