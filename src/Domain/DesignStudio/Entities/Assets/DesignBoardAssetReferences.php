<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Assets;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The asset files a board references, one per path.
 *
 * @property DesignBoardAssetReference[] $elements
 * @method DesignBoardAssetReference[] getElements()
 * @method DesignBoardAssetReference|null getByUniqueKey(string $uniqueKey)
 */
class DesignBoardAssetReferences extends ObjectSet
{
    /**
     * @param string $assetPath
     * @return DesignBoardAssetReference|null
     */
    public function getByAssetPath(string $assetPath): ?DesignBoardAssetReference
    {
        return $this->getByUniqueKey(DesignBoardAssetReference::uniqueKeyStatic($assetPath));
    }

    /**
     * The version of the whole set: changes when a file is added, removed or replaced; null for an empty set.
     * @return string|null 40 hex characters
     */
    public function computeAssetsHash(): ?string
    {
        $lines = [];
        foreach ($this->getElements() as $designBoardAssetReference) {
            $lines[] = $designBoardAssetReference->assetPath . ':' . ($designBoardAssetReference->contentHash ?? '-');
        }
        if (!$lines) {
            return null;
        }
        sort($lines);
        return sha1(implode("\n", $lines));
    }
}
