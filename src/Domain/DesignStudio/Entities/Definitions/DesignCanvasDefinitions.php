<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Definitions;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Canvases of one design project.
 *
 * @property DesignCanvasDefinition[] $elements
 * @method DesignCanvasDefinition[] getElements()
 * @method DesignCanvasDefinition first()
 */
class DesignCanvasDefinitions extends ObjectSet
{
}
