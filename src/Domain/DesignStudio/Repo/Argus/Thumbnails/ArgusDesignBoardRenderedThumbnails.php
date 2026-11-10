<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\Argus\Thumbnails;

use DDD\Domain\DesignStudio\Entities\Thumbnails\DesignBoardRenderedThumbnails;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Libs\Config;

/**
 * Renders a board with the renderer service. Endpoint and token come from the config `DesignStudio.renderer`:
 * `endpoint` (with its request method; a path is relative to the Argus base URL), `endpointEnv` (name of an env
 * variable with an absolute URL that replaces it) and `tokenEnv` (name of the env variable with the caller token, sent
 * as `X-Render-Token`; the token itself is never in a config file).
 *
 * Failures never throw: 401 / 404 / 429 / 502–504 and transport errors leave failureReason set (isRetryableFailure),
 * nothing is cached, the caller retries later with backoff.
 */
#[ArgusLoad(loadEndpoint: 'POST:/designBoardRenderer/thumbnails', cacheLevel: ArgusCache::CACHELEVEL_NONE, timeout: 130)]
class ArgusDesignBoardRenderedThumbnails extends DesignBoardRenderedThumbnails
{
    use ArgusTrait;

    /** @var float Longest wait in seconds for the request bodies of a batch to be written before the answers are awaited */
    public const float WRITE_REQUESTS_SECONDS = 30.0;

    /** @var bool A call of startRender() is in flight (finishRender() completes it) */
    protected bool $isRenderInFlight = false;

    /**
     * Renders several boards concurrently: every call is sent and fully written first, then the answers are
     * collected. Each instance keeps its own result / failure.
     * @param ArgusDesignBoardRenderedThumbnails[] $renderers
     * @return void
     */
    public static function renderAll(array $renderers): void
    {
        static::startAll($renderers);
        static::finishAll($renderers);
    }

    /**
     * Sends the calls of every renderer and moves them until their request bodies are written (the service renders
     * while the caller does other work, e.g. stores the previous batch).
     * @param ArgusDesignBoardRenderedThumbnails[] $renderers
     * @return void
     */
    public static function startAll(array $renderers): void
    {
        foreach ($renderers as $renderer) {
            $renderer->startRender();
        }
        foreach ($renderers as $renderer) {
            if ($renderer->isRenderInFlight) {
                $renderer->advanceArgusLoadRequestsUntilWritten(static::WRITE_REQUESTS_SECONDS);
            }
        }
    }

    /**
     * Waits for the answers of startAll().
     * @param ArgusDesignBoardRenderedThumbnails[] $renderers
     * @return void
     */
    public static function finishAll(array $renderers): void
    {
        foreach ($renderers as $renderer) {
            $renderer->finishRender();
        }
    }

    /**
     * @return void
     */
    public function startRender(): void
    {
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = static::getConfiguredEndpoint();
        // a refused / timed-out call answers null instead of throwing out of the load
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
        // false: the load already ran to the end (blocking) — nothing to receive
        $this->isRenderInFlight = $this->sendArgusLoadRequests(useArgusEntityCache: false, useApiACallCache: false);
        if (!$this->isRenderInFlight) {
            $this->markUnanswered();
        }
    }

    /**
     * @return void
     */
    public function finishRender(): void
    {
        if (!$this->isRenderInFlight) {
            return;
        }
        $this->isRenderInFlight = false;
        $this->receiveArgusLoadResponses();
        $this->markUnanswered();
    }

    /**
     * @return string the renderer endpoint with its request method
     */
    public static function getConfiguredEndpoint(): string
    {
        $endpointEnv = Config::get('DesignStudio.renderer.endpointEnv');
        $endpointOverride = $endpointEnv ? (string)(Config::getEnv((string)$endpointEnv) ?: '') : '';
        if ($endpointOverride !== '') {
            return 'POST:' . $endpointOverride;
        }
        return (string)(Config::get('DesignStudio.renderer.endpoint') ?: 'POST:/designBoardRenderer/thumbnails');
    }

    /**
     * @return void
     */
    protected function markUnanswered(): void
    {
        if (!$this->getArgusSettings()->isLoadedSuccessfully && $this->failureReason === null) {
            $this->failureReason = 'the renderer did not answer';
        }
    }

    protected function getLoadPayload(): ?array
    {
        $headers = [
            // never hand the application's own bearer token (Argus default) to the renderer
            'Authorization' => 'none',
            'Content-Type' => 'application/json',
        ];
        $tokenEnv = Config::get('DesignStudio.renderer.tokenEnv');
        $token = $tokenEnv ? (string)(Config::getEnv((string)$tokenEnv) ?: '') : '';
        if ($token !== '') {
            $headers['X-Render-Token'] = $token;
        }
        return [
            'body' => [
                'html' => $this->html,
                'width' => $this->width,
                'height' => $this->height,
                'themes' => [['themeHash' => $this->themeHash, 'css' => $this->themeStyle]],
            ],
            'headers' => $headers,
        ];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $rejectionReason = $callResponseData === null
            ? ($apiOperation?->rejectionReason ?? 'transport failure')
            : static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation);
        $data = $rejectionReason === null ? $this->getResponseDataFromArgusResponse($callResponseData) : null;
        if ($rejectionReason !== null || !isset($data->images) || !is_array($data->images)) {
            $this->failureStatusCode = $apiOperation?->responseStatusCode;
            $this->failureReason = $rejectionReason
                ?? (is_object($callResponseData) && isset($callResponseData->error) ? (string)$callResponseData->error : 'unexpected answer');
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        foreach ($data->images as $image) {
            $png = base64_decode((string)($image->full ?? ''), true);
            if ($png !== false && str_starts_with($png, "\x89PNG") && (string)($image->themeHash ?? '') === $this->themeHash) {
                $this->png = $png;
            }
        }
        if ($this->png === null) {
            $this->failureReason = 'the answer holds no picture for the theme';
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $this->postProcessLoadResponse($callResponseData, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(hash('sha256', $this->html . $this->themeHash));
    }
}
