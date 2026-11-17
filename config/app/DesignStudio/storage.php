<?php

declare(strict_types=1);

/**
 * Where the design studio keeps files it must hold itself (snapshots of commented board versions, thumbnails): a
 * named store of the framework's object storage (OBJECT_STORAGE_<NAME>_*) and a key prefix inside it. Without a
 * configured store nothing is kept; the studio then cannot show the version a comment was written on once the board
 * changed, and thumbnails cannot be stored. An application file replaces this default as a whole.
 *
 * `thumbnailStore`: a store name of its own for the thumbnails (null = `store`), e.g. the same bucket with
 * `timeoutSeconds: 5, maxRetries: 3` (framework object storage, Core 3.35.2): a hanging upload is retried quickly and
 * fails one picture in bounded time instead of holding the render run. Point it at the same bucket, or the thumbnails
 * stored before are not found any more.
 */
return [
    'store' => 'design-studio',
    'thumbnailStore' => null,
    'prefix' => 'design-studio',
];
