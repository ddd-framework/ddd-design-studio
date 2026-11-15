<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\Argus\Exports;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardVectorExport;
use DDD\Domain\DesignStudio\Repo\Argus\Thumbnails\ArgusDesignBoardRenderedThumbnails;
use DDD\Infrastructure\Libs\Config;

/**
 * Exports a board as SVG with the renderer service (job `vector`, renderer 1.2.0). The endpoint is the configured
 * thumbnails endpoint (`DesignStudio.renderer`: `endpoint` / `endpointEnv`) with its last path segment `thumbnails`
 * replaced by `vector`; token as for thumbnails.
 *
 * Failures never throw: failureReason is set (404 = the renderer has no `vector` job yet, see isRetryableFailure).
 */
#[ArgusLoad(loadEndpoint: 'POST:/designBoardRenderer/vector', cacheLevel: ArgusCache::CACHELEVEL_NONE, timeout: 130)]
class ArgusDesignBoardVectorExport extends DesignBoardVectorExport
{
    use ArgusTrait;

    /** @var string The job path segment of the renderer */
    public const string JOB_PATH_SEGMENT = 'vector';

    /**
     * Sends the export and waits for its answer; the result or the failure is on this instance afterwards.
     * @return void
     */
    public function export(): void
    {
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = static::getConfiguredEndpoint();
        // a refused / timed-out call answers null instead of throwing out of the load
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
        $this->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        if (!$this->getArgusSettings()->isLoadedSuccessfully && $this->failureReason === null) {
            $this->failureReason = 'the renderer did not answer';
        }
    }

    /**
     * @return string the renderer's `vector` endpoint with its request method: the thumbnails endpoint with the job
     *                segment replaced
     */
    public static function getConfiguredEndpoint(): string
    {
        $thumbnailsEndpoint = ArgusDesignBoardRenderedThumbnails::getConfiguredEndpoint();
        return (string)preg_replace('~/thumbnails(?=$|[?#])~', '/' . static::JOB_PATH_SEGMENT, $thumbnailsEndpoint, 1);
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
        $body = [
            'html' => $this->html,
            'width' => $this->width,
            'height' => $this->height,
            'theme' => ['themeHash' => $this->themeHash, 'css' => $this->themeStyle],
        ];
        if ($this->fullPage) {
            $body['fullPage'] = true;
        }
        if ($this->runsScripts) {
            $body['scripts'] = true;
        }
        if ($this->region !== null) {
            $body['region'] = [
                'x' => $this->region->x,
                'y' => $this->region->y,
                'width' => $this->region->width,
                'height' => $this->region->height,
            ];
        }
        return [
            'body' => $body,
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
        $svg = isset($data->svg) ? base64_decode((string)$data->svg, true) : false;
        if ($rejectionReason !== null || $svg === false || !str_contains($svg, '<svg')) {
            $this->failureStatusCode = $apiOperation?->responseStatusCode;
            $this->failureReason = $rejectionReason
                ?? (is_object($callResponseData) && isset($callResponseData->error) ? (string)$callResponseData->error : 'the answer holds no SVG');
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $this->svg = $svg;
        $png = base64_decode((string)($data->png ?? ''), true);
        $this->png = $png !== false && str_starts_with($png, "\x89PNG") ? $png : null;
        $comparisonPng = base64_decode((string)($data->comparison ?? ''), true);
        $this->comparisonPng = $comparisonPng !== false && str_starts_with($comparisonPng, "\x89PNG") ? $comparisonPng : null;
        $this->differingPixelRatio = isset($data->differingPixelRatio) && is_numeric($data->differingPixelRatio) ? (float)$data->differingPixelRatio : null;
        $this->exportWidth = isset($data->width) ? (int)$data->width : null;
        $this->exportHeight = isset($data->height) ? (int)$data->height : null;
        $this->postProcessLoadResponse($callResponseData, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(hash('sha256', $this->html . $this->themeHash . ($this->region?->toRegionText() ?? '')));
    }
}
