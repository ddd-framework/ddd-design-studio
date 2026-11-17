<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;

/**
 * The pictures a render request could not produce, one per render target (the newest failure of a target wins).
 *
 * @property DesignStudioThumbnailRenderFailure[] $elements
 * @method DesignStudioThumbnailRenderFailure[] getElements()
 * @method DesignStudioThumbnailRenderFailure first()
 * @method DesignStudioThumbnailRenderFailure|null getByUniqueKey(string $uniqueKey)
 */
class DesignStudioThumbnailRenderFailures extends ObjectSet
{
    /**
     * @param string|null $storedLines DesignStudioThumbnailRenderRequest::$failedBoards
     * @return static
     */
    public static function fromStoredLines(?string $storedLines): static
    {
        $designStudioThumbnailRenderFailures = new static();
        foreach (explode("\n", (string)$storedLines) as $storedLine) {
            $designStudioThumbnailRenderFailure = DesignStudioThumbnailRenderFailure::fromStoredLine($storedLine);
            if ($designStudioThumbnailRenderFailure === null) {
                continue;
            }
            // a target recorded twice (a later run failed it again): the newest line wins
            $existingFailure = $designStudioThumbnailRenderFailures->getByRenderTargetKey((string)$designStudioThumbnailRenderFailure->renderTargetKey);
            if ($existingFailure) {
                $designStudioThumbnailRenderFailures->remove($existingFailure);
            }
            $designStudioThumbnailRenderFailures->add($designStudioThumbnailRenderFailure);
        }
        return $designStudioThumbnailRenderFailures;
    }

    /**
     * @param string $renderTargetKey
     * @return DesignStudioThumbnailRenderFailure|null
     */
    public function getByRenderTargetKey(string $renderTargetKey): ?DesignStudioThumbnailRenderFailure
    {
        return $this->getByUniqueKey(DesignStudioThumbnailRenderFailure::uniqueKeyStatic($renderTargetKey));
    }

    /**
     * @param DesignDirectoryBoard $designDirectoryBoard a board or a device frame of one
     * @return DesignStudioThumbnailRenderFailure|null the failure of exactly this board version
     */
    public function findForBoard(DesignDirectoryBoard $designDirectoryBoard): ?DesignStudioThumbnailRenderFailure
    {
        $designStudioThumbnailRenderFailure = $this->getByRenderTargetKey($designDirectoryBoard->getRenderTargetKey());
        return $designStudioThumbnailRenderFailure && $designStudioThumbnailRenderFailure->isForBoard($designDirectoryBoard)
            ? $designStudioThumbnailRenderFailure
            : null;
    }
}
