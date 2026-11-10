<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Definitions;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The design projects of the application.
 *
 * @property DesignProjectDefinition[] $elements
 * @method DesignProjectDefinition[] getElements()
 * @method DesignProjectDefinition first()
 */
class DesignProjectDefinitions extends ObjectSet
{
}
