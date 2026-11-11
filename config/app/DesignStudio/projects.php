<?php

declare(strict_types=1);

/**
 * Design projects of the studio: which folders of the checkout are canvases. The studio reads `canvas.json` and the
 * board files from these folders when a canvas is opened; nothing of it is stored in the database. An application
 * file replaces this default (no projects) as a whole.
 *
 * Example:
 *
 *     return [
 *         [
 *             'key' => 'app',                      // project key (URL segment)
 *             'label' => 'App',
 *             'path' => 'app',
 *             'code' => 'APP',                     // optional: first part of every board code (`APP ONB-W2`)
 *             'canvases' => [
 *                 [
 *                     'key' => 'onboarding',       // canvas key (URL segment)
 *                     'label' => 'Onboarding',
 *                     'folder' => 'design/onboarding',   // relative to the project root; must be in the image
 *                     'boards' => ['*'],           // file-name patterns of the boards on this canvas
 *                     'sections' => ['*'],         // key patterns of the title notes that are sections
 *                     'referenceTheme' => null,    // `<themeId>:<mode>` the canvas is designed in, or null
 *                     'code' => 'ONB',             // optional: board codes `APP ONB-W2` (DesignBoardCodesService)
 *                 ],
 *             ],
 *         ],
 *     ];
 */
return [];
