<?php

declare(strict_types=1);

/**
 * Where the design studio keeps files it must hold itself (snapshots of commented board versions, thumbnails): a
 * named store of the framework's object storage (OBJECT_STORAGE_<NAME>_*) and a key prefix inside it. Without a
 * configured store nothing is kept; the studio then cannot show the version a comment was written on once the board
 * changed, and thumbnails cannot be stored. An application file replaces this default as a whole.
 */
return [
    'store' => 'design-studio',
    'prefix' => 'design-studio',
];
