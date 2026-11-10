<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Traits;

use DDD\Domain\DesignStudio\Services\DesignAccessPolicyService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * Shared by the design studio controller traits: every action asks the access policy explicitly (the studio's
 * repositories deny foreign accounts every row as well).
 */
trait DesignStudioViewerTrait
{
    /**
     * @return Account the authenticated account, which may view the studio
     * @throws ForbiddenException
     */
    protected function requireDesignStudioViewer(): Account
    {
        $authAccount = AuthService::instance()->getAccount();
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        if (!$authAccount || !$designAccessPolicyService->canView($authAccount)) {
            throw new ForbiddenException('This account may not use the design studio');
        }
        return $authAccount;
    }
}
