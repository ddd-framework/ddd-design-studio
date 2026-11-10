<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\Service;

/**
 * Who may use the design studio. The default lets accounts with an admin role do everything and everyone else
 * nothing. An application with its own rule (e.g. staff only, no partner users) subclasses this service and registers
 * the subclass under this class' service id.
 */
class DesignAccessPolicyService extends Service
{
    /**
     * @param Account|null $account the authenticated account, null without one
     * @return bool may see canvases, boards, comments and statuses
     */
    public function canView(?Account $account): bool
    {
        return (bool)($account?->roles?->isAdmin());
    }

    /**
     * @param Account|null $account
     * @return bool may write, resolve and delete comments
     */
    public function canComment(?Account $account): bool
    {
        return $this->canView($account);
    }

    /**
     * @param Account|null $account
     * @return bool may set the status of a board
     */
    public function canSetStatus(?Account $account): bool
    {
        return $this->canView($account);
    }

    /**
     * @param Account|null $account
     * @return bool may mark a comment as a decision
     */
    public function canDecide(?Account $account): bool
    {
        return $this->canView($account);
    }

    /**
     * @param Account $account
     * @return string the name shown as the author of a comment or a status change
     */
    public function getAccountDisplayName(Account $account): string
    {
        return 'Account ' . $account->id;
    }
}
