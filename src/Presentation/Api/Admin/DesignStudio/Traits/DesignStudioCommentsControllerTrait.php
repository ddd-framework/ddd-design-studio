<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\DesignStudio\Traits;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Domain\DesignStudio\Services\DesignStudioCanvasViewsService;
use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioBoardRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCanvasGetRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentCreateRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentGetRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentPatchRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentReplyRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentResolveRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentsGetRequestDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioCommentsGetResponseDto;
use DDD\Presentation\Api\Admin\DesignStudio\Dtos\DesignStudioDeleteResponseDto;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Delete;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Patch;
use DDD\Presentation\Base\Router\Routes\Post;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Design studio routes for comment threads. An application uses the trait in a controller of its own.
 */
trait DesignStudioCommentsControllerTrait
{
    use DesignStudioViewerTrait;

    /**
     * The comments of a canvas, thread roots and replies, oldest first. Threads on boards that are not in the
     * checkout are included (the page lists them apart).
     * @param DesignStudioCanvasGetRequestDto $requestDto
     * @param DesignStudioCommentsGetRequestDto $filterDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentsGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    #[Get('/canvases/{projectKey}/{canvasKey}/comments')]
    #[Summary('DesignStudio Comments List')]
    public function getComments(
        DesignStudioCanvasGetRequestDto $requestDto,
        DesignStudioCommentsGetRequestDto $filterDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentsGetResponseDto {
        $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $responseDto = new DesignStudioCommentsGetResponseDto();
        $responseDto->designStudioComments = $designStudioCommentsService->findForCanvas(
            (string)$canvasDefinition->projectKey,
            (string)$canvasDefinition->canvasKey,
            (bool)($filterDto->onlyOpenThreads ?? false),
            $filterDto->sinceId
        );
        return $responseDto;
    }

    /**
     * Thread roots that will be deleted soon unless something happens in them (retention of the studio).
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentsGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Get('/comments/expiring')]
    #[Summary('DesignStudio Comments Expiring List')]
    public function getExpiringComments(DesignStudioCommentsService $designStudioCommentsService): DesignStudioCommentsGetResponseDto
    {
        $this->requireDesignStudioViewer();
        $responseDto = new DesignStudioCommentsGetResponseDto();
        $responseDto->designStudioComments = $designStudioCommentsService->findExpiringThreads();
        return $responseDto;
    }

    /**
     * Starts a comment thread on a board of the checkout; the commented version is kept as a snapshot.
     * @param DesignStudioBoardRequestDto $requestDto
     * @param DesignStudioCommentCreateRequestDto $commentDto
     * @param DesignStudioCanvasViewsService $designStudioCanvasViewsService
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Post('/canvases/{projectKey}/{canvasKey}/boards/{boardKey}/comments', requirements: ['boardKey' => '[^/]+'])]
    #[Summary('DesignStudio Comment Creation')]
    public function createComment(
        DesignStudioBoardRequestDto $requestDto,
        DesignStudioCommentCreateRequestDto $commentDto,
        DesignStudioCanvasViewsService $designStudioCanvasViewsService,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $canvasDefinition = $designStudioCanvasViewsService->getCanvasDefinition($requestDto->projectKey, $requestDto->canvasKey);
        $designDirectoryBoard = $designStudioCanvasViewsService->getDirectoryBoard($canvasDefinition, $requestDto->boardKey);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->createThread(
            $canvasDefinition,
            $designDirectoryBoard,
            $commentDto->text,
            $commentDto->x,
            $commentDto->y,
            (bool)($commentDto->isDecision ?? false),
            DesignStudioComment::AUTHOR_KIND_USER
        );
        return $responseDto;
    }

    /**
     * Adds a reply to the thread of a comment.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentReplyRequestDto $replyDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto the reply
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Post('/comments/{designStudioCommentId}/replies', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Reply')]
    public function replyToComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentReplyRequestDto $replyDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->reply($designStudioComment, $replyDto->text, DesignStudioComment::AUTHOR_KIND_USER);
        return $responseDto;
    }

    /**
     * Changes the text (only its author) and / or the decision flag of a comment.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentPatchRequestDto $patchDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Patch('/comments/{designStudioCommentId}', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Update')]
    public function patchComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentPatchRequestDto $patchDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->changeComment($designStudioComment, $patchDto->text, $patchDto->isDecision);
        return $responseDto;
    }

    /**
     * Resolves the thread of a comment, optionally with a last reply.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentResolveRequestDto $resolveDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto the thread root
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Post('/comments/{designStudioCommentId}/resolve', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Resolve Thread')]
    public function resolveComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentResolveRequestDto $resolveDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->resolveThread(
            $designStudioComment,
            $resolveDto->replyText,
            DesignStudioComment::AUTHOR_KIND_USER
        );
        return $responseDto;
    }

    /**
     * Reopens the thread of a comment.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto the thread root
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Post('/comments/{designStudioCommentId}/reopen', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Reopen Thread')]
    public function reopenComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->reopenThread($designStudioComment);
        return $responseDto;
    }

    /**
     * Keeps the thread of a comment: its expiry starts again from now.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioCommentGetResponseDto the thread root
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    #[Post('/comments/{designStudioCommentId}/keep', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Keep Thread')]
    public function keepComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioCommentGetResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $responseDto = new DesignStudioCommentGetResponseDto();
        $responseDto->designStudioComment = $designStudioCommentsService->keepThread($designStudioComment);
        return $responseDto;
    }

    /**
     * Deletes a comment; deleting a thread root deletes its replies.
     * @param DesignStudioCommentGetRequestDto $requestDto
     * @param DesignStudioCommentsService $designStudioCommentsService
     * @return DesignStudioDeleteResponseDto
     * @throws BadRequestException
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws NotFoundException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    #[Delete('/comments/{designStudioCommentId}', requirements: ['designStudioCommentId' => '\d+'])]
    #[Summary('DesignStudio Comment Deletion')]
    public function deleteComment(
        DesignStudioCommentGetRequestDto $requestDto,
        DesignStudioCommentsService $designStudioCommentsService
    ): DesignStudioDeleteResponseDto {
        $this->requireDesignStudioViewer();
        $designStudioCommentsService->throwErrors = true;
        $designStudioComment = $designStudioCommentsService->find($requestDto->designStudioCommentId);
        $designStudioCommentsService->deleteComment($designStudioComment);
        return new DesignStudioDeleteResponseDto();
    }
}
