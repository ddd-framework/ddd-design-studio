<?php

/**
 * Design studio: where the application mounts the studio's routes. The studio builds the delivery URLs of board
 * files and thumbnails from it (they are loaded by `<iframe src>` / `<img src>`).
 */
return [
    // path of the content routes (DesignStudioContentControllerTrait), without a trailing slash
    'contentBasePath' => '/api/admin/designStudio/content',
];
