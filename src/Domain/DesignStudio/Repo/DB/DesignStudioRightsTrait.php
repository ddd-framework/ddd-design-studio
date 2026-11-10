<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Repo\DB;

use DDD\Domain\DesignStudio\Services\DesignAccessPolicyService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;

/**
 * Rights of the design studio's review state: a row is visible to and writable by accounts the DesignAccessPolicyService
 * lets view the studio; every other account (and no account) gets no row. Updates, creates and deletes delegate to
 * the read rights (framework default), so the same rule gates every write; finer rules (comment, status, decide) are
 * checked by the services.
 */
trait DesignStudioRightsTrait
{
    /**
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool true when the deny condition was added, false when the account may view the studio
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        if ($designAccessPolicyService->canView(AuthService::instance()->getAccount())) {
            return false;
        }
        $alias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.id IS NULL");
        return true;
    }
}
