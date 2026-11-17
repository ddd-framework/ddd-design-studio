<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The result of one try of one picture (DesignStudioThumbnailRenderRequestsService::renderPicture()).
 */
class DesignStudioThumbnailRenderOutcome extends ValueObject
{
    /** @var string The thumbnail is stored */
    public const string RESULT_STORED = 'STORED';

    /** @var string This picture cannot be rendered (the renderer refused it, e.g. HTTP 400): not tried again */
    public const string RESULT_REFUSED = 'REFUSED';

    /** @var string This try failed (renderer busy or unreachable, a throwing render or store): tried again with delay */
    public const string RESULT_RETRYABLE = 'RETRYABLE';

    /** @var string|null RESULT_* */
    public ?string $result = null;

    /** @var string|null Why the try failed */
    public ?string $error = null;

    /** @var bool The renderer itself is busy or unreachable (the request shows WAITING_FOR_RENDERER) */
    public bool $isRendererUnavailable = false;

    /**
     * @param string $result
     * @param string|null $error
     * @param bool $isRendererUnavailable
     * @return static
     */
    public static function create(string $result, ?string $error = null, bool $isRendererUnavailable = false): static
    {
        $designStudioThumbnailRenderOutcome = new static();
        $designStudioThumbnailRenderOutcome->result = $result;
        $designStudioThumbnailRenderOutcome->error = $error !== null ? mb_substr($error, 0, 1000) : null;
        $designStudioThumbnailRenderOutcome->isRendererUnavailable = $isRendererUnavailable;
        return $designStudioThumbnailRenderOutcome;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
