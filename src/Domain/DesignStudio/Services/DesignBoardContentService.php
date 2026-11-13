<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Delivery of board files to the browser. A board is shown in a sandboxed `<iframe src>` (and its thumbnail in an
 * `<img src>`), which cannot send the Authorization header, so the delivery URLs carry a content token: a JWT with
 * the scope `designStudioContent` and the account, issued AND expiring on half-hour boundaries (valid 30–60 minutes,
 * the same token for every request of a window, so URLs stay the same and the browser cache works).
 *
 * The board file comes from the canvas folder of the running checkout. A version that is no longer there (the board
 * changed after a comment was written on it) comes from the snapshot the comment kept.
 */
class DesignBoardContentService extends Service
{
    /** @var string The site view's navigation script (addSiteNavigationScript()); its hash is the CSP source */
    public const string SITE_NAVIGATION_SCRIPT = <<<'JS'
(function () {
  var post = function (message) { message.source = 'design-studio-site'; window.parent.postMessage(message, '*'); };
  document.addEventListener('click', function (event) {
    var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!anchor) return;
    event.preventDefault();
    var href = anchor.getAttribute('href') || '';
    if (href.charAt(0) === '#') {
      var target = href.length > 1 ? document.getElementById(decodeURIComponent(href.slice(1))) : null;
      if (target) target.scrollIntoView({ behavior: 'smooth' });
      else window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
    post({ type: 'navigate', href: href });
  }, true);
  document.addEventListener('submit', function (event) { event.preventDefault(); }, true);
  var isScrollQueued = false;
  window.addEventListener('scroll', function () {
    if (isScrollQueued) return;
    isScrollQueued = true;
    window.requestAnimationFrame(function () {
      isScrollQueued = false;
      post({ type: 'scroll', top: Math.round(window.scrollY) });
    });
  }, { passive: true });
  var reportHeight = function () {
    post({ type: 'height', height: Math.ceil(Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0)) });
  };
  if (window.ResizeObserver) new ResizeObserver(reportHeight).observe(document.documentElement);
  window.addEventListener('load', reportHeight);
  reportHeight();
})();
JS;

    /** @var string Scope claim of a content token */
    public const string CONTENT_TOKEN_SCOPE = 'designStudioContent';

    /** @var int Validity of a content token in seconds */
    public const int CONTENT_TOKEN_VALIDITY_SECONDS = 3600;

    /** @var int Window the token's issue time is quantised to, in seconds */
    public const int CONTENT_TOKEN_QUANTUM_SECONDS = 1800;

    /** @var int Cache lifetime of a themed board HTML in seconds (key: content hash + theme hash) */
    public const int THEMED_HTML_CACHE_SECONDS = 86400;

    /**
     * @param Account $account an account that may view the studio
     * @return string the content token
     */
    public function createContentToken(Account $account): string
    {
        $now = time();
        $windowStart = $now - ($now % static::CONTENT_TOKEN_QUANTUM_SECONDS);
        // the expiry is quantised too: a "now + validity" expiry would give a new token per second, so every poll
        // would change every delivery URL and the canvas would reload its images and iframes
        $expiresAt = new DateTime();
        $expiresAt->setTimestamp($windowStart + static::CONTENT_TOKEN_VALIDITY_SECONDS);
        return JWTPayload::createJWTFromParameters([
            'accountId' => $account->id,
            'scope' => static::CONTENT_TOKEN_SCOPE,
            'time' => $windowStart,
        ], null, $expiresAt);
    }

    /**
     * Validates a content token and makes its account the auth account of the request. Every failure is a 404, so
     * the delivery routes reveal nothing.
     * @param string|null $contentToken
     * @return Account
     * @throws NotFoundException
     */
    public function authenticateContentToken(?string $contentToken): Account
    {
        $parameters = $contentToken ? JWTPayload::getParametersFromJWT($contentToken) : false;
        if (
            !is_array($parameters)
            || ($parameters['scope'] ?? null) !== static::CONTENT_TOKEN_SCOPE
            || !isset($parameters['accountId'])
        ) {
            throw new NotFoundException('Design content not found');
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AccountsService $accountsService */
            $accountsService = DDDService::instance()->getService(AccountsService::class);
            /** @var Account|null $account */
            $account = $accountsService->find((int)$parameters['accountId']);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        if (!$account || !$designAccessPolicyService->canView($account)) {
            throw new NotFoundException('Design content not found');
        }
        AuthService::instance()->setAccount($account);
        return $account;
    }

    /**
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey file name of the board
     * @param string|null $contentHash the wanted version, null = the file of the checkout
     * @return string|null the board file; null when the board is not part of the canvas, or the version is neither
     *                     the checkout's nor kept as a snapshot
     * @throws InternalErrorException
     */
    public function getBoardHtml(DesignCanvasDefinition $canvasDefinition, string $boardKey, ?string $contentHash = null): ?string
    {
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $boardContent = $designDirectoryReaderService->readBoardContent($canvasDefinition, $boardKey);
        if ($contentHash === null) {
            return $boardContent;
        }
        if (!preg_match('/^[0-9a-f]{40}$/', $contentHash)) {
            return null;
        }
        if ($boardContent !== null && DesignDirectoryReaderService::computeContentHash($boardContent) === $contentHash) {
            return $boardContent;
        }
        if (!$designDirectoryReaderService->isBoardKeyOfCanvas($boardKey, $canvasDefinition)) {
            return null;
        }
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        $snapshotContent = $designStudioFilesService->readSnapshot($designStudioFilesService->getSnapshotObjectKey($contentHash));
        // the stored object must be the version asked for
        return $snapshotContent !== null && DesignDirectoryReaderService::computeContentHash($snapshotContent) === $contentHash
            ? $snapshotContent
            : null;
    }

    /**
     * The board as delivered to the sandboxed iframe: without `<script>` elements (the sandbox and the CSP block
     * scripts anyway; removing them keeps the browser from requesting them and from logging a blocked execution per
     * board), unless the canvas runs board scripts (DesignCanvasDefinition::$runsBoardScripts), and, with a theme,
     * with the theme's `<style>` element first in `<head>`.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey
     * @param string|null $contentHash the wanted version, null = the file of the checkout
     * @param string|null $themeStyle the `<style data-design-theme>` element, null = no theme
     * @param bool $useCache false for bulk work (thumbnail rendering): many theme / board pairs must not fill the
     *        cache the canvas iframes rely on
     * @return string|null null as getBoardHtml()
     * @throws InternalErrorException
     */
    public function getBoardHtmlForSandbox(
        DesignCanvasDefinition $canvasDefinition,
        string $boardKey,
        ?string $contentHash = null,
        ?string $themeStyle = null,
        bool $useCache = true
    ): ?string {
        $cacheKey = $contentHash !== null && $themeStyle !== null
            ? 'designStudio.themedHtml.' . $contentHash . '.' . sha1($themeStyle) . ($canvasDefinition->runsBoardScripts ? '.scripts' : '')
            : null;
        if ($useCache && $cacheKey !== null) {
            $cachedHtml = Cache::instance()->get($cacheKey);
            if (is_string($cachedHtml)) {
                return $cachedHtml;
            }
        }
        $boardHtml = $this->getBoardHtml($canvasDefinition, $boardKey, $contentHash);
        if ($boardHtml === null) {
            return null;
        }
        $sandboxHtml = static::applyTheme($canvasDefinition->runsBoardScripts ? $boardHtml : static::removeScripts($boardHtml), $themeStyle);
        if ($useCache && $cacheKey !== null) {
            Cache::instance()->set($cacheKey, $sandboxHtml, static::THEMED_HTML_CACHE_SECONDS);
        }
        return $sandboxHtml;
    }

    /**
     * The site view's board: the sandboxed HTML (getBoardHtmlForSandbox()) with the studio's navigation script at the
     * end. On a canvas without board scripts it is the only one the page runs (the board's own scripts are removed;
     * the CSP admits this script by its hash, so inline handlers and `javascript:` URLs stay blocked); on a canvas with
     * board scripts it runs next to them. It runs in an opaque origin and only tells
     * the parent page, by `postMessage` with `source: 'design-studio-site'`:
     * - `navigate` with the `href` of a clicked link (the parent maps `/path` and `board:<file>` to a page; external
     *   links are not followed: every click is prevented; a `#fragment` link scrolls the page itself),
     * - `scroll` with the page's scroll position (the parent moves the comment pins with the page),
     * - `height` with the page height whenever it changes.
     * The page keeps the device's viewport height and scrolls itself, so `vh` units behave as on the device.
     * Forms are not submitted.
     * @param string $sandboxHtml
     * @return string
     */
    public static function addSiteNavigationScript(string $sandboxHtml): string
    {
        $scriptElement = '<script>' . static::SITE_NAVIGATION_SCRIPT . '</script>';
        $bodyEndPosition = strripos($sandboxHtml, '</body>');
        return $bodyEndPosition === false
            ? $sandboxHtml . $scriptElement
            : substr($sandboxHtml, 0, $bodyEndPosition) . $scriptElement . substr($sandboxHtml, $bodyEndPosition);
    }

    /**
     * @return string the CSP source of the navigation script (`'sha256-…'`)
     */
    public static function getSiteNavigationScriptSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', static::SITE_NAVIGATION_SCRIPT, true)) . "'";
    }

    /**
     * @param string $html
     * @return string the HTML without `<script>` elements
     */
    public static function removeScripts(string $html): string
    {
        return (string)preg_replace(['#<script\b[^>]*>.*?</script\s*>#is', '#<script\b[^>]*/>#i'], '', $html);
    }

    /**
     * @param string $html
     * @param string|null $themeStyle the `<style data-design-theme>` element, null = no theme
     * @return string the HTML with the theme first in `<head>` (in front of the document when it has no `<head>`)
     */
    public static function applyTheme(string $html, ?string $themeStyle): string
    {
        if ($themeStyle === null || $themeStyle === '') {
            return $html;
        }
        if (preg_match('/<head\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $themeStyle, $matches[0][1] + strlen($matches[0][0]), 0);
        }
        return $themeStyle . $html;
    }
}
