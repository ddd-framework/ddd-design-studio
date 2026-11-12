<?php

declare(strict_types=1);

/**
 * Limits for reading design files from the checkout. An application file replaces the module default as a whole.
 */
return [
    // largest board file read (boards embed images as data URIs); a larger file is listed as a warning
    'boardMaximumBytes' => 16 * 1024 * 1024,
    // largest canvas.json read
    'canvasJsonMaximumBytes' => 4 * 1024 * 1024,
    // largest asset file delivered (images under <canvas folder>/assets/); a larger file is not delivered
    'assetMaximumBytes' => 5 * 1024 * 1024,
];
