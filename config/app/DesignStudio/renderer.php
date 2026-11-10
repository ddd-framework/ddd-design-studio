<?php

declare(strict_types=1);

/**
 * Design studio: the thumbnail renderer (a small HTTP service that turns a board file + theme into a PNG; contract
 * and example build in renderer/README.md of the module). Without it the canvas shows frames with titles
 * when zoomed out and the live boards when zoomed in.
 *
 * A config file replaces the studio's default file as a whole: keep every key.
 */
return [
    // false: no thumbnails are requested, rendered or shown; no worker is needed
    'enabled' => false,
    // 'POST:<absolute URL>' or 'POST:<path>' relative to the Argus base URL
    'endpoint' => 'POST:/designBoardRenderer/thumbnails',
    // name of an env variable with an absolute URL that replaces the endpoint (local / tunnel setups); null = none
    'endpointEnv' => 'DESIGN_BOARD_RENDERER_ENDPOINT',
    // name of the env variable with the caller token sent as X-Render-Token; null or an empty variable = no header
    'tokenEnv' => 'DESIGN_BOARD_RENDERER_TOKEN',
    // boards rendered at once = shards of a render request (the render consumer needs at least as many replicas)
    'concurrency' => 2,
    // name of an env variable that overrides the concurrency per environment; null = none
    'concurrencyEnv' => 'DESIGN_RENDER_CONCURRENCY',
    // part of every thumbnail key: change it when the renderer's output changes (browser, fonts) and every thumbnail
    // is rendered anew
    'rendererVersion' => 'playwright-1.64.1',
];
