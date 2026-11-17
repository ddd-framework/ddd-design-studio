<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How far a render request is: its pictures by status.
 */
class DesignStudioThumbnailRenderPictureCounts extends ValueObject
{
    /** @var int Pictures still to render (a retry with delay included) */
    public int $queuedCount = 0;

    /** @var int Pictures whose thumbnail this request stored */
    public int $doneCount = 0;

    /** @var int Pictures given up, each with its reason */
    public int $failedCount = 0;

    /** @var int Pictures not rendered: the board is gone, or the thumbnail was there already */
    public int $skippedCount = 0;

    /**
     * @return int every picture of the request
     */
    public function getTotalCount(): int
    {
        return $this->queuedCount + $this->doneCount + $this->failedCount + $this->skippedCount;
    }

    /**
     * @return bool done, failed and skipped add up to the total
     */
    public function isComplete(): bool
    {
        return $this->queuedCount === 0;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
