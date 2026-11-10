<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Directory;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Sections of one canvas, top to bottom.
 *
 * @property DesignDirectorySection[] $elements
 * @method DesignDirectorySection[] getElements()
 * @method DesignDirectorySection first()
 */
class DesignDirectorySections extends ObjectSet
{
}
