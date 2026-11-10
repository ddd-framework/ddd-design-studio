<?php

declare(strict_types=1);

namespace DDD\Tests\DesignStudio\Services;

use DDD\Domain\DesignStudio\Services\DesignStudioBoardStatusesService;
use PHPUnit\Framework\TestCase;

/**
 * The status keys of the studio come from the config `DesignStudio.statuses`; the first one is the default.
 */
class DesignStudioBoardStatusesServiceTest extends TestCase
{
    public function testReadsTheConfiguredStatuses(): void
    {
        $statuses = (new DesignStudioBoardStatusesService())->getStatuses();
        $this->assertSame(['DRAFT', 'IN_REVIEW', 'CHOSEN', 'REJECTED'], $statuses);
    }
}
