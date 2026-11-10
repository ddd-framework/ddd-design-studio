<?php

declare(strict_types=1);

namespace DDD\Domain\DesignStudio\Services;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComments;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Repo\DB\Comments\DBDesignStudioComment;
use DDD\Domain\DesignStudio\Repo\DB\Comments\DBDesignStudioComments;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Comment threads on design boards. A thread names its board by keys and the board version by content hash; starting
 * a thread keeps a snapshot of that version so it stays viewable after the board changed. Every activity in a thread
 * moves `lastActivityAt` of its root; a thread idle longer than the configured time is deleted (config
 * `DesignStudio.retention`), decisions only when the config says so.
 *
 * @method DesignStudioComment find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method DesignStudioComments findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBDesignStudioComment getEntityRepoClassInstance()
 * @method DBDesignStudioComments getEntitySetRepoClassInstance()
 */
class DesignStudioCommentsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = DesignStudioComment::class;

    /** @var int Fallback of the config `DesignStudio.retention.threadIdleDays` */
    public const int DEFAULT_THREAD_IDLE_DAYS = 180;

    /** @var int Fallback of the config `DesignStudio.retention.expiryWarningDays` */
    public const int DEFAULT_EXPIRY_WARNING_DAYS = 14;

    /** @var string Cache key marking that expired threads were deleted within the last day */
    public const string PRUNE_MARKER_CACHE_KEY = 'designStudio.commentsPruned';

    /** @var int The automatic prune runs at most once in this many seconds */
    public const int PRUNE_INTERVAL_SECONDS = 86400;

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @param bool $onlyOpenThreads true: only comments of threads that are not resolved
     * @param int|null $sinceId only comments with a larger id (polling)
     * @return DesignStudioComments roots and replies of the canvas, oldest first
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findForCanvas(string $projectKey, string $canvasKey, bool $onlyOpenThreads = false, ?int $sinceId = null): DesignStudioComments
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.projectKey = :projectKey")
            ->andWhere("$alias.canvasKey = :canvasKey")
            ->setParameter('projectKey', $projectKey)
            ->setParameter('canvasKey', $canvasKey)
            ->addOrderBy("$alias.id", 'ASC');
        if ($sinceId !== null) {
            $queryBuilder->andWhere("$alias.id > :sinceId")->setParameter('sinceId', $sinceId);
        }
        $designStudioComments = $repoClass->find($queryBuilder, false) ?? new DesignStudioComments();
        if (!$onlyOpenThreads) {
            return $designStudioComments;
        }
        $resolvedRootIds = [];
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            if ($designStudioComment->isThreadRoot() && ($designStudioComment->isResolved ?? false)) {
                $resolvedRootIds[(int)$designStudioComment->id] = true;
            }
        }
        $openDesignStudioComments = new DesignStudioComments();
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            $rootId = (int)($designStudioComment->parentDesignStudioCommentId ?? $designStudioComment->id);
            if (!isset($resolvedRootIds[$rootId])) {
                $openDesignStudioComments->add($designStudioComment);
            }
        }
        return $openDesignStudioComments;
    }

    /**
     * @param string $projectKey
     * @param string $canvasKey
     * @return string changes with every new, changed, resolved, reopened or deleted comment of the canvas
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getCanvasFingerprint(string $projectKey, string $canvasKey): string
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("COUNT($alias.id) AS commentCount, MAX($alias.id) AS newestId, MAX($alias.lastActivityAt) AS newestActivity")
            ->where("$alias.projectKey = :projectKey")
            ->andWhere("$alias.canvasKey = :canvasKey")
            ->setParameter('projectKey', $projectKey)
            ->setParameter('canvasKey', $canvasKey);
        $this->getEntityRepoClassInstance()::applyReadRightsQuery($queryBuilder);
        $row = $queryBuilder->getQuery()->getArrayResult()[0] ?? [];
        $newestActivity = $row['newestActivity'] ?? '';
        return implode('.', [
            (int)($row['commentCount'] ?? 0),
            (int)($row['newestId'] ?? 0),
            $newestActivity instanceof \DateTimeInterface ? $newestActivity->getTimestamp() : (string)$newestActivity,
        ]);
    }

    /**
     * Starts a thread on a board of the checkout and keeps a snapshot of the commented version.
     * @param DesignCanvasDefinition $canvasDefinition
     * @param DesignDirectoryBoard $designDirectoryBoard the board as it is in the checkout now
     * @param string $text
     * @param float|null $x pin position in board pixels, null = a comment on the whole board
     * @param float|null $y
     * @param bool $isDecision
     * @param string $authorKind DesignStudioComment::AUTHOR_KIND_*
     * @param string|null $authorName overrides the account's display name (agents)
     * @return DesignStudioComment
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function createThread(
        DesignCanvasDefinition $canvasDefinition,
        DesignDirectoryBoard $designDirectoryBoard,
        string $text,
        ?float $x,
        ?float $y,
        bool $isDecision,
        string $authorKind,
        ?string $authorName = null
    ): DesignStudioComment {
        $this->requireMayComment($isDecision);
        $designStudioComment = $this->createComment($text, $authorKind, $authorName);
        $designStudioComment->projectKey = $canvasDefinition->projectKey;
        $designStudioComment->canvasKey = $canvasDefinition->canvasKey;
        $designStudioComment->boardKey = $designDirectoryBoard->boardKey;
        $designStudioComment->contentHash = $designDirectoryBoard->contentHash;
        $designStudioComment->x = $x;
        $designStudioComment->y = $y;
        $designStudioComment->isDecision = $isDecision;
        $designStudioComment->lastActivityAt = new DateTime();

        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        $boardContent = $designDirectoryReaderService->readBoardContent($canvasDefinition, (string)$designDirectoryBoard->boardKey);
        // the snapshot is the version the hash names; a file that changed between listing and reading is not kept
        if ($boardContent !== null && DesignDirectoryReaderService::computeContentHash($boardContent) === $designDirectoryBoard->contentHash) {
            /** @var DesignStudioFilesService $designStudioFilesService */
            $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
            $designStudioComment->snapshotObjectKey = $designStudioFilesService->storeSnapshot((string)$designDirectoryBoard->contentHash, $boardContent);
        }
        return $this->update($designStudioComment);
    }

    /**
     * Adds a reply to the thread of a comment.
     * @param DesignStudioComment $designStudioComment any comment of the thread
     * @param string $text
     * @param string $authorKind DesignStudioComment::AUTHOR_KIND_*
     * @param string|null $authorName overrides the account's display name (agents)
     * @return DesignStudioComment the reply
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function reply(DesignStudioComment $designStudioComment, string $text, string $authorKind, ?string $authorName = null): DesignStudioComment
    {
        $this->requireMayComment(false);
        $rootDesignStudioComment = $this->getThreadRoot($designStudioComment);
        $replyDesignStudioComment = $this->createComment($text, $authorKind, $authorName);
        $replyDesignStudioComment->projectKey = $rootDesignStudioComment->projectKey;
        $replyDesignStudioComment->canvasKey = $rootDesignStudioComment->canvasKey;
        $replyDesignStudioComment->boardKey = $rootDesignStudioComment->boardKey;
        $replyDesignStudioComment->contentHash = $rootDesignStudioComment->contentHash;
        $replyDesignStudioComment->parentDesignStudioCommentId = $rootDesignStudioComment->id;
        $replyDesignStudioComment->isDecision = false;
        $replyDesignStudioComment = $this->update($replyDesignStudioComment);
        $this->keepThread($rootDesignStudioComment);
        return $replyDesignStudioComment;
    }

    /**
     * Changes the text (only its author) and / or the decision flag of a comment.
     * @param DesignStudioComment $designStudioComment
     * @param string|null $text
     * @param bool|null $isDecision
     * @return DesignStudioComment
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function changeComment(DesignStudioComment $designStudioComment, ?string $text, ?bool $isDecision): DesignStudioComment
    {
        $this->requireMayComment($isDecision !== null);
        if ($text !== null) {
            $authAccount = AuthService::instance()->getAccount();
            if (!$authAccount || (int)($designStudioComment->authorAccountId ?? 0) !== (int)$authAccount->id) {
                throw new ForbiddenException('Only the author can change the text of a comment');
            }
            $designStudioComment->text = $this->requireText($text);
        }
        if ($isDecision !== null) {
            $designStudioComment->isDecision = $isDecision;
        }
        $designStudioComment = $this->update($designStudioComment);
        $this->keepThread($this->getThreadRoot($designStudioComment));
        return $designStudioComment;
    }

    /**
     * Resolves the thread of a comment, optionally with a reply first (the agent's "done: …").
     * @param DesignStudioComment $designStudioComment any comment of the thread
     * @param string|null $replyText
     * @param string $authorKind kind of the reply's author
     * @param string|null $authorName overrides the account's display name (agents)
     * @return DesignStudioComment the resolved thread root
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function resolveThread(
        DesignStudioComment $designStudioComment,
        ?string $replyText,
        string $authorKind,
        ?string $authorName = null
    ): DesignStudioComment {
        $this->requireMayComment(false);
        $rootDesignStudioComment = $this->getThreadRoot($designStudioComment);
        if ($replyText !== null && trim($replyText) !== '') {
            $this->reply($rootDesignStudioComment, $replyText, $authorKind, $authorName);
        }
        $rootDesignStudioComment->isResolved = true;
        $rootDesignStudioComment->resolvedAt = new DateTime();
        $rootDesignStudioComment->resolvedByName = $authorName ?? $this->getAuthAccountDisplayName();
        $rootDesignStudioComment->lastActivityAt = new DateTime();
        return $this->update($rootDesignStudioComment);
    }

    /**
     * @param DesignStudioComment $designStudioComment any comment of the thread
     * @return DesignStudioComment the reopened thread root
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function reopenThread(DesignStudioComment $designStudioComment): DesignStudioComment
    {
        $this->requireMayComment(false);
        $rootDesignStudioComment = $this->getThreadRoot($designStudioComment);
        $rootDesignStudioComment->isResolved = false;
        $rootDesignStudioComment->resolvedAt = null;
        $rootDesignStudioComment->resolvedByName = null;
        $rootDesignStudioComment->lastActivityAt = new DateTime();
        return $this->update($rootDesignStudioComment);
    }

    /**
     * Counts as activity: the thread's expiry starts again from now.
     * @param DesignStudioComment $designStudioComment any comment of the thread
     * @return DesignStudioComment the thread root
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function keepThread(DesignStudioComment $designStudioComment): DesignStudioComment
    {
        $this->requireMayComment(false);
        $rootDesignStudioComment = $this->getThreadRoot($designStudioComment);
        $rootDesignStudioComment->lastActivityAt = new DateTime();
        $rootDesignStudioComment->updatePartialIgnoringRights('lastActivityAt');
        return $rootDesignStudioComment;
    }

    /**
     * Deletes a comment; deleting a thread root deletes its replies and, when no other thread uses it, its snapshot.
     * @param DesignStudioComment $designStudioComment
     * @return void
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function deleteComment(DesignStudioComment $designStudioComment): void
    {
        $this->requireMayComment(false);
        $this->deleteWithSnapshot($designStudioComment);
    }

    /**
     * @param DesignStudioComment $designStudioComment
     * @return DesignStudioComment the root of the comment's thread (the comment itself when it is one)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getThreadRoot(DesignStudioComment $designStudioComment): DesignStudioComment
    {
        if ($designStudioComment->isThreadRoot()) {
            return $designStudioComment;
        }
        $rootDesignStudioComment = $this->find($designStudioComment->parentDesignStudioCommentId);
        if (!$rootDesignStudioComment) {
            throw new BadRequestException('The thread of this comment does not exist any more');
        }
        return $rootDesignStudioComment;
    }

    /**
     * @param int|null $withinDays threads expiring within this many days; null = the configured warning time
     * @return DesignStudioComments thread roots that will be deleted soon unless something happens in them
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findExpiringThreads(?int $withinDays = null): DesignStudioComments
    {
        $withinDays ??= (int)(Config::get('DesignStudio.retention.expiryWarningDays') ?? static::DEFAULT_EXPIRY_WARNING_DAYS);
        return $this->findThreadRootsIdleFor(-$withinDays);
    }

    /**
     * Deletes every thread that is idle longer than the configured time. Runs as the system (no account rights).
     * @param bool $dryRun true: only report
     * @return DesignStudioComments the expired thread roots (deleted unless dry run)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function pruneExpiredThreads(bool $dryRun = false): DesignStudioComments
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $expiredDesignStudioComments = $this->findThreadRootsIdleFor(0);
            if (!$dryRun) {
                foreach ($expiredDesignStudioComments->getElements() as $expiredDesignStudioComment) {
                    $this->deleteWithSnapshot($expiredDesignStudioComment);
                }
            }
            return $expiredDesignStudioComments;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Deletes expired threads when that has not happened within the last day. Called when a canvas is opened, so the
     * studio needs no scheduled job.
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function pruneExpiredThreadsIfDue(): void
    {
        $cache = Cache::instance();
        if ($cache->get(static::PRUNE_MARKER_CACHE_KEY)) {
            return;
        }
        $cache->set(static::PRUNE_MARKER_CACHE_KEY, time(), static::PRUNE_INTERVAL_SECONDS);
        $this->pruneExpiredThreads();
    }

    /**
     * @param int $dayOffset 0 = idle longer than the configured time now; -N = will be within N days
     * @return DesignStudioComments thread roots, oldest activity first
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findThreadRootsIdleFor(int $dayOffset): DesignStudioComments
    {
        $threadIdleDays = (int)(Config::get('DesignStudio.retention.threadIdleDays') ?? static::DEFAULT_THREAD_IDLE_DAYS);
        $decisionIdleDays = Config::get('DesignStudio.retention.decisionIdleDays');
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.parentDesignStudioCommentId IS NULL")->addOrderBy("$alias.lastActivityAt", 'ASC');
        $threadLimit = (new DateTime())->modify('-' . max(0, $threadIdleDays + $dayOffset) . ' days');
        if ($decisionIdleDays === null) {
            $queryBuilder->andWhere("$alias.isDecision = :isDecision AND $alias.lastActivityAt < :threadLimit")
                ->setParameter('isDecision', false);
        } else {
            $decisionLimit = (new DateTime())->modify('-' . max(0, (int)$decisionIdleDays + $dayOffset) . ' days');
            $queryBuilder->andWhere(
                "($alias.isDecision = :isDecision AND $alias.lastActivityAt < :threadLimit)"
                . " OR ($alias.isDecision = :isDecisionTrue AND $alias.lastActivityAt < :decisionLimit)"
            )
                ->setParameter('isDecision', false)
                ->setParameter('isDecisionTrue', true)
                ->setParameter('decisionLimit', $decisionLimit->format(DateTime::SIMPLE));
        }
        $queryBuilder->setParameter('threadLimit', $threadLimit->format(DateTime::SIMPLE));
        return $repoClass->find($queryBuilder, false) ?? new DesignStudioComments();
    }

    /**
     * @param DesignStudioComment $designStudioComment
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    protected function deleteWithSnapshot(DesignStudioComment $designStudioComment): void
    {
        $snapshotObjectKey = $designStudioComment->isThreadRoot() ? ($designStudioComment->snapshotObjectKey ?? null) : null;
        $this->delete($designStudioComment);
        if ($snapshotObjectKey === null) {
            return;
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("COUNT($alias.id)")
            ->where("$alias.snapshotObjectKey = :snapshotObjectKey")
            ->setParameter('snapshotObjectKey', $snapshotObjectKey);
        if ((int)$queryBuilder->getQuery()->getSingleScalarResult() === 0) {
            /** @var DesignStudioFilesService $designStudioFilesService */
            $designStudioFilesService = DDDService::instance()->getService(DesignStudioFilesService::class);
            $designStudioFilesService->deleteSnapshot($snapshotObjectKey);
        }
    }

    /**
     * @param string $text
     * @param string $authorKind
     * @param string|null $authorName
     * @return DesignStudioComment a comment with text and author, not stored yet
     * @throws BadRequestException
     */
    protected function createComment(string $text, string $authorKind, ?string $authorName): DesignStudioComment
    {
        $designStudioComment = new DesignStudioComment();
        $designStudioComment->text = $this->requireText($text);
        $designStudioComment->isResolved = false;
        $designStudioComment->authorKind = $authorKind;
        $designStudioComment->authorAccountId = AuthService::instance()->getAccount()?->id;
        $designStudioComment->authorName = $authorName ?? $this->getAuthAccountDisplayName();
        return $designStudioComment;
    }

    /**
     * @param string $text
     * @return string the trimmed text
     * @throws BadRequestException when it is empty
     */
    protected function requireText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new BadRequestException('A comment needs a text');
        }
        return $text;
    }

    /**
     * @param bool $withDecision true when the action sets or clears the decision flag
     * @return void
     * @throws ForbiddenException
     */
    protected function requireMayComment(bool $withDecision): void
    {
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        $authAccount = AuthService::instance()->getAccount();
        if (!$designAccessPolicyService->canComment($authAccount) || ($withDecision && !$designAccessPolicyService->canDecide($authAccount))) {
            throw new ForbiddenException('This account may not do that in the design studio');
        }
    }

    /**
     * @return string|null display name of the authenticated account
     */
    protected function getAuthAccountDisplayName(): ?string
    {
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount) {
            return null;
        }
        /** @var DesignAccessPolicyService $designAccessPolicyService */
        $designAccessPolicyService = DDDService::instance()->getService(DesignAccessPolicyService::class);
        return $designAccessPolicyService->getAccountDisplayName($authAccount);
    }
}
