<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Assets\DesignBoardAsset;
use DDD\Domain\DesignStudio\Entities\Assets\DesignBoardAssetReference;
use DDD\Domain\DesignStudio\Entities\Assets\DesignBoardAssetReferences;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Image files a board loads from its canvas' `assets/` folder instead of embedding them as data URIs.
 *
 * A board references a file relative to the canvas folder: `assets/<path>` or `./assets/<path>` in a `src`, `href`,
 * `poster` or `srcset` attribute or in a CSS `url(…)`. The board file stays as it is in the checkout (a browser that
 * opens it from disk resolves the paths itself); the studio changes the references only on delivery:
 * - to the sandboxed iframe: each reference becomes a URL of the content route with the content token and the file's
 *   version (a relative URL would lose the token);
 * - to the thumbnail renderer: each reference becomes a data URI (the renderer loads nothing from the network).
 *
 * A board's assets have a version of their own (DesignBoardAssetReferences::computeAssetsHash()); the board version
 * stays the git blob hash of the board file. When a thread starts on a board, the referenced files are kept with the
 * board snapshot, so an earlier commented version shows the images it had.
 */
class DesignBoardAssetsService extends Service
{
    /** @var string[] Media type per file extension (lower case); other extensions are no assets */
    public const array ASSET_MEDIA_TYPES = [
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
    ];

    /** @var int Longest asset path below `assets/` */
    public const int ASSET_PATH_MAXIMUM_LENGTH = 255;

    /**
     * An asset reference in an attribute (`src`, `href`, `poster`, also `data-src` / `xlink:href`) or a CSS `url()`.
     * Group `prefix`: what stays in front; group `assetPath`: the path below `assets/`.
     */
    protected const string ASSET_REFERENCE_PATTERN =
        '#(?<prefix>\b(?:src|href|poster)\s*=\s*["\']?|url\(\s*["\']?)(?:\./)?assets/(?<assetPath>[A-Za-z0-9._\-/]+)#i';

    /** @var string A `srcset` attribute; group `candidates`: its value */
    protected const string SRCSET_PATTERN = '#(?<prefix>\bsrcset\s*=\s*)(?<quote>["\'])(?<candidates>.*?)(?P=quote)#is';

    /** @var string One image candidate of a `srcset` value that is an asset reference */
    protected const string SRCSET_CANDIDATE_PATTERN = '#(?<prefix>(?:^|,)\s*)(?:\./)?assets/(?<assetPath>[A-Za-z0-9._\-/]+)#';

    /**
     * @param string $assetPath path below `assets/`
     * @return bool true for a path of plain segments (letters, digits, `.`, `_`, `-`; none starting with a dot, so no
     *              `..` and no dotfile) with an image extension of ASSET_MEDIA_TYPES
     */
    public static function isValidAssetPath(string $assetPath): bool
    {
        if ($assetPath === '' || strlen($assetPath) > static::ASSET_PATH_MAXIMUM_LENGTH) {
            return false;
        }
        foreach (explode('/', $assetPath) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || !preg_match('/^[A-Za-z0-9._\-]+$/', $segment)) {
                return false;
            }
        }
        return static::getMediaTypeForAssetPath($assetPath) !== null;
    }

    /**
     * @param string $assetPath
     * @return string|null the media type of the file extension, null when it is no asset type
     */
    public static function getMediaTypeForAssetPath(string $assetPath): ?string
    {
        return static::ASSET_MEDIA_TYPES[strtolower(pathinfo($assetPath, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * @param string $html a board file
     * @return string[] the valid asset paths it references, each once, in order of appearance
     */
    public static function findReferencedAssetPaths(string $html): array
    {
        $assetPaths = [];
        static::replaceAssetReferences($html, static function (string $assetPath) use (&$assetPaths): ?string {
            $assetPaths[$assetPath] = true;
            return null;
        });
        return array_keys($assetPaths);
    }

    /**
     * The asset files of the given paths as they are in this checkout.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string[] $assetPaths
     * @return DesignBoardAssetReferences one per path; a missing file has no content hash
     */
    public function findCurrentAssetReferences(DesignCanvasDefinition $canvasDefinition, array $assetPaths): DesignBoardAssetReferences
    {
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $designBoardAssetReferences = new DesignBoardAssetReferences();
        foreach ($assetPaths as $assetPath) {
            $designBoardAssetReference = new DesignBoardAssetReference();
            $designBoardAssetReference->assetPath = $assetPath;
            $assetFilePath = $designDirectoryReaderService->getAssetFilePath($canvasDefinition, $assetPath);
            $designBoardAssetReference->contentHash = $assetFilePath === null
                ? null
                : $designDirectoryReaderService->getAssetContentHash($assetFilePath);
            $designBoardAssetReferences->add($designBoardAssetReference);
        }
        return $designBoardAssetReferences;
    }

    /**
     * The asset files a version of a board shows: the checkout's files for the checkout's version (or no version),
     * the files kept with the snapshot for an earlier version (a path the snapshot did not keep: the checkout's file).
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $boardKey
     * @param string|null $contentHash the delivered version, null = the file of the checkout
     * @param string $boardHtml the delivered board file
     * @return DesignBoardAssetReferences
     * @throws InternalErrorException when the store fails
     */
    public function findAssetReferencesForBoardVersion(
        DesignCanvasDefinition $canvasDefinition,
        string $boardKey,
        ?string $contentHash,
        string $boardHtml
    ): DesignBoardAssetReferences {
        $assetPaths = static::findReferencedAssetPaths($boardHtml);
        $currentAssetReferences = $this->findCurrentAssetReferences($canvasDefinition, $assetPaths);
        if ($contentHash === null || $assetPaths === []) {
            return $currentAssetReferences;
        }
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        if ($designDirectoryReaderService->getCheckoutContentHash($canvasDefinition, $boardKey) === $contentHash) {
            return $currentAssetReferences;
        }
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        $snapshotAssetReferences = $designStudioFilesService->readAssetManifest($contentHash);
        if ($snapshotAssetReferences === null) {
            return $currentAssetReferences;
        }
        foreach ($currentAssetReferences->getElements() as $currentAssetReference) {
            $snapshotAssetReference = $snapshotAssetReferences->getByAssetPath((string)$currentAssetReference->assetPath);
            if ($snapshotAssetReference !== null && $snapshotAssetReference->contentHash !== null) {
                $currentAssetReference->contentHash = $snapshotAssetReference->contentHash;
            }
        }
        return $currentAssetReferences;
    }

    /**
     * Points the asset references of a board at the content route: `<assetBasePath>/<path>?<tokenQuery>&v=<version>`.
     * A reference without a file stays as it is (the image is missing, as when the board is opened from disk).
     * @param string $html the board as delivered to the iframe
     * @param DesignBoardAssetReferences $designBoardAssetReferences the files this version shows
     * @param string $assetBasePath path of the canvas' asset route, without a trailing slash
     * @param string $tokenQuery `token=<content token>`
     * @return string
     */
    public function rewriteAssetReferencesToContentRoute(
        string $html,
        DesignBoardAssetReferences $designBoardAssetReferences,
        string $assetBasePath,
        string $tokenQuery
    ): string {
        return static::replaceAssetReferences(
            $html,
            static function (string $assetPath) use ($designBoardAssetReferences, $assetBasePath, $tokenQuery): ?string {
                $contentHash = $designBoardAssetReferences->getByAssetPath($assetPath)?->contentHash;
                if ($contentHash === null) {
                    return null;
                }
                return $assetBasePath . '/' . implode('/', array_map('rawurlencode', explode('/', $assetPath)))
                    . '?' . $tokenQuery . '&v=' . $contentHash;
            }
        );
    }

    /**
     * Embeds the referenced asset files of the checkout as data URIs (for the thumbnail renderer, which loads nothing
     * from the network).
     * @param string $html
     * @param DesignCanvasDefinition $canvasDefinition
     * @return string
     */
    public function inlineAssetReferences(string $html, DesignCanvasDefinition $canvasDefinition): string
    {
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $dataUris = [];
        return static::replaceAssetReferences(
            $html,
            static function (string $assetPath) use ($canvasDefinition, $designDirectoryReaderService, &$dataUris): ?string {
                if (!array_key_exists($assetPath, $dataUris)) {
                    $assetFilePath = $designDirectoryReaderService->getAssetFilePath($canvasDefinition, $assetPath);
                    $content = $assetFilePath === null ? false : @file_get_contents($assetFilePath);
                    $dataUris[$assetPath] = is_string($content)
                        ? 'data:' . static::getMediaTypeForAssetPath($assetPath) . ';base64,' . base64_encode($content)
                        : null;
                }
                return $dataUris[$assetPath];
            }
        );
    }

    /**
     * One asset file for delivery: the checkout's file when it is the asked version (or no version is asked), else
     * the file kept with a snapshot.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $assetPath
     * @param string|null $contentHash the wanted version, null = the file of the checkout
     * @return DesignBoardAsset|null null when the path is no valid asset path or the version is not available
     * @throws InternalErrorException when the store fails
     */
    public function readAsset(DesignCanvasDefinition $canvasDefinition, string $assetPath, ?string $contentHash = null): ?DesignBoardAsset
    {
        if (!static::isValidAssetPath($assetPath) || ($contentHash !== null && !preg_match('/^[0-9a-f]{40}$/', $contentHash))) {
            return null;
        }
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $assetFilePath = $designDirectoryReaderService->getAssetFilePath($canvasDefinition, $assetPath);
        $content = $assetFilePath === null ? false : @file_get_contents($assetFilePath);
        if (is_string($content)) {
            $currentContentHash = DesignDirectoryReaderService::computeContentHash($content);
            if ($contentHash === null || $currentContentHash === $contentHash) {
                return $this->createAsset($assetPath, $currentContentHash, $content);
            }
        }
        if ($contentHash === null) {
            return null;
        }
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        $snapshotContent = $designStudioFilesService->readAssetSnapshot($contentHash);
        // the stored object must be the version asked for
        if ($snapshotContent === null || DesignDirectoryReaderService::computeContentHash($snapshotContent) !== $contentHash) {
            return null;
        }
        return $this->createAsset($assetPath, $contentHash, $snapshotContent);
    }

    /**
     * Keeps the asset files a board version references next to its snapshot: each file content-addressed (shared by
     * every board and version that shows it) and a manifest of the version's paths. The first thread on a version
     * writes the manifest; later threads on the same version reuse it.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param string $contentHash the board version
     * @param string $boardContent the board file of that version
     * @return void
     * @throws InternalErrorException when the store fails
     */
    public function storeAssetSnapshotsForBoardVersion(DesignCanvasDefinition $canvasDefinition, string $contentHash, string $boardContent): void
    {
        $assetPaths = static::findReferencedAssetPaths($boardContent);
        if ($assetPaths === []) {
            return;
        }
        /** @var DesignStudioFilesService $designStudioFilesService */
        $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
        if ($designStudioFilesService->readAssetManifest($contentHash) !== null) {
            return;
        }
        $keptAssetReferences = new DesignBoardAssetReferences();
        foreach ($assetPaths as $assetPath) {
            $designBoardAsset = $this->readAsset($canvasDefinition, $assetPath);
            if ($designBoardAsset === null) {
                continue;
            }
            if ($designStudioFilesService->storeAssetSnapshot((string)$designBoardAsset->contentHash, $designBoardAsset->content, (string)$designBoardAsset->mediaType) === null) {
                // no store configured: nothing is kept
                return;
            }
            $designBoardAssetReference = new DesignBoardAssetReference();
            $designBoardAssetReference->assetPath = $assetPath;
            $designBoardAssetReference->contentHash = $designBoardAsset->contentHash;
            $keptAssetReferences->add($designBoardAssetReference);
        }
        if ($keptAssetReferences->count()) {
            $designStudioFilesService->storeAssetManifest($contentHash, $keptAssetReferences);
        }
    }

    /**
     * @param string $assetPath
     * @param string $contentHash
     * @param string $content
     * @return DesignBoardAsset
     */
    protected function createAsset(string $assetPath, string $contentHash, string $content): DesignBoardAsset
    {
        $designBoardAsset = new DesignBoardAsset();
        $designBoardAsset->assetPath = $assetPath;
        $designBoardAsset->contentHash = $contentHash;
        $designBoardAsset->mediaType = static::getMediaTypeForAssetPath($assetPath);
        $designBoardAsset->content = $content;
        return $designBoardAsset;
    }

    /**
     * Replaces every valid asset reference of the HTML by what the callback returns for its path.
     * @param string $html
     * @param callable $replacementForAssetPath fn(string $assetPath): ?string — the new URL, null = keep the reference
     * @return string
     */
    protected static function replaceAssetReferences(string $html, callable $replacementForAssetPath): string
    {
        $replaceReference = static function (array $matches) use ($replacementForAssetPath): string {
            $assetPath = $matches['assetPath'];
            if (!static::isValidAssetPath($assetPath)) {
                return $matches[0];
            }
            $replacement = $replacementForAssetPath($assetPath);
            return $replacement === null ? $matches[0] : $matches['prefix'] . $replacement;
        };
        $html = (string)preg_replace_callback(
            static::SRCSET_PATTERN,
            static fn(array $srcsetMatches): string => $srcsetMatches['prefix'] . $srcsetMatches['quote']
                . preg_replace_callback(static::SRCSET_CANDIDATE_PATTERN, $replaceReference, $srcsetMatches['candidates'])
                . $srcsetMatches['quote'],
            $html
        );
        return (string)preg_replace_callback(static::ASSET_REFERENCE_PATTERN, $replaceReference, $html);
    }
}
