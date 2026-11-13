<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Entities\Views;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The device frames of a responsive board, in the order they are shown.
 *
 * @property DesignStudioBoardFrameView[] $elements
 * @method DesignStudioBoardFrameView[] getElements()
 * @method DesignStudioBoardFrameView first()
 */
class DesignStudioBoardFrameViews extends ObjectSet
{
}
