<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatus;
use DDD\Domain\DesignStudio\Entities\BoardStatuses\DesignStudioBoardStatuses;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Repo\DB\BoardStatuses\DBDesignStudioBoardStatus;
use DDD\Domain\DesignStudio\Repo\DB\BoardStatuses\DBDesignStudioBoardStatuses;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Review statuses of design boards: one row per board that has a status other than the default. Status keys come
 * from the config `DesignStudio.statuses`; its first entry is the status of a board without a row.
 *
 * @method DesignStudioBoardStatus find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioBoardStatuses findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioBoardStatus getEntityRepoClassInstance()
 * @method DBDesignStudioBoardStatuses getEntitySetRepoClassInstance()
 */
class DesignStudioBoardStatusesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioBoardStatus::class;

    /** @var string[] Fallback of the config `DesignStudio.statuses` */
    public const array DEFAULT_STATUSES = ['DRAFT', 'IN_REVIEW', 'CHOSEN', 'REJECTED'];

    /**
     * @return string[] the configured status keys in display order; the first is the default status
     */
    public function getStatuses(): array
    {
        $statuses = Config::get('DesignStudio.statuses');
        return is_array($statuses) && $statuses !== [] ? array_values(array_map('strval', $statuses)) : static::DEFAULT_STATUSES;
    }

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @return DesignStudioBoardStatuses the stored statuses of the canvas' boards
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findForCanvas(string $projectKey, string $canvasKey): DesignStudioBoardStatuses
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.projectKey = :projectKey")
            ->andWhere("$alias.canvasKey = :canvasKey")
            ->setParameter('projectKey', $projectKey)
            ->setParameter('canvasKey', $canvasKey);
        return $repoClass->find($queryBuilder, false) ?? new DesignStudioBoardStatuses();
    }

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @param string $boardKey
     * @return DesignStudioBoardStatus|null null when the board has no stored status
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findForBoard(string $projectKey, string $canvasKey, string $boardKey): ?DesignStudioBoardStatus
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.projectKey = :projectKey")
            ->andWhere("$alias.canvasKey = :canvasKey")
            ->andWhere("$alias.boardKey = :boardKey")
            ->setParameter('projectKey', $projectKey)
            ->setParameter('canvasKey', $canvasKey)
            ->setParameter('boardKey', $boardKey);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * Sets the status of a board of the checkout and remembers the board version it was set on.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard the board as it is in the checkout now
     * @param string $status one of getStatuses()
     * @param string|null $changedByName overrides the account's display name (agents)
     * @return DesignStudioBoardStatus
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function setStatus(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $designDirectoryBoard,
        string $status,
        ?string $changedByName = null
    ): DesignStudioBoardStatus {
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        $authAccount = AuthService::instance()->getAccount();
        if (!$designAccessPolicyService->canSetStatus($authAccount)) {
            throw new ForbiddenException('This account may not set a board status');
        }
        if (!in_array($status, $this->getStatuses(), true)) {
            throw new BadRequestException("Unknown board status '$status'; configured: " . implode(', ', $this->getStatuses()));
        }
        $designStudioBoardStatus = $this->findForBoard(
            (string)$canvasDefinition->projectKey,
            (string)$canvasDefinition->canvasKey,
            (string)$designDirectoryBoard->boardKey
        ) ?? new DesignStudioBoardStatus();
        $designStudioBoardStatus->projectKey = $canvasDefinition->projectKey;
        $designStudioBoardStatus->canvasKey = $canvasDefinition->canvasKey;
        $designStudioBoardStatus->boardKey = $designDirectoryBoard->boardKey;
        $designStudioBoardStatus->status = $status;
        $designStudioBoardStatus->contentHash = $designDirectoryBoard->contentHash;
        $designStudioBoardStatus->changedByAccountId = $authAccount?->id;
        $designStudioBoardStatus->changedByName = $changedByName ?? ($authAccount ? $designAccessPolicyService->getAccountDisplayName($authAccount) : null);
        return $this->update($designStudioBoardStatus);
    }
}
