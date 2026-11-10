<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Themes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Themes a provider offers, in display order.
 *
 * @property DesignTheme[] $elements
 * @method DesignTheme[] getElements()
 * @method DesignTheme first()
 */
class DesignThemes extends ObjectSet
{
}
