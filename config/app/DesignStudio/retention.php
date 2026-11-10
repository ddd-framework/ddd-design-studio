<?php

declare(strict_types=1);

/**
 * How long review state of the design studio is kept. An application file replaces the module default as a whole,
 * so it names every key.
 */
return [
    // a comment thread without activity (comment, reply, resolve, reopen, keep) is deleted after this many days
    'threadIdleDays' => 180,
    // the studio lists threads that expire within this many days
    'expiryWarningDays' => 14,
    // idle days after which a decision thread is deleted; null = decisions never expire
    'decisionIdleDays' => null,
];
