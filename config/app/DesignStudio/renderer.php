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
    // seconds one render call may take; a slower answer counts as unanswered and the picture is tried again later
    // (pictures rendered at once = consumer processes of render_design_studio_thumbnails; there is no setting for it)
    'timeoutSeconds' => 60,
    // part of every thumbnail key: change it when the renderer's output changes (browser, fonts) and every thumbnail
    // is rendered anew
    'rendererVersion' => 'playwright-1.64.1',
];
