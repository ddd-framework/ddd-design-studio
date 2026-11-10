<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Directory;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Boards of one canvas, top to bottom and left to right.
 *
 * @property DesignDirectoryBoard[] $elements
 * @method DesignDirectoryBoard[] getElements()
 * @method DesignDirectoryBoard first()
 */
class DesignDirectoryBoards extends ObjectSet
{
}
