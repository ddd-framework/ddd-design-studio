<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Domain\DesignStudio\Entities\Definitions\DesignCanvasDefinition;
use DDD\Domain\DesignStudio\Services\DesignStudioCanvasViewsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Base of the design studio console commands. They are how agents and developers work with the studio from a
 * workspace: the boards are the files of that checkout, the review state is in the database the workspace uses.
 */
abstract class DesignStudioCommand extends Command
{
    /**
     * Adds the `project` and `canvas` arguments.
     * @return void
     */
    protected function addCanvasArguments(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Key of the design project (config DesignStudio.projects)')
            ->addArgument('canvas', InputArgument::REQUIRED, 'Key of the canvas within the project');
    }

    /**
     * Commands run as the application's account for CLI operations.
     * @return void
     */
    protected function authenticate(): void
    {
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }
    }

    /**
     * @param InputInterface $input
     * @return DesignCanvasDefinition the canvas named by the `project` and `canvas` arguments
     * @throws BadRequestException
     * @throws NotFoundException
     */
    protected function getCanvasDefinition(InputInterface $input): DesignCanvasDefinition
    {
        return $this->getDesignStudioCanvasViewsService()->getCanvasDefinition(
            (string)$input->getArgument('project'),
            (string)$input->getArgument('canvas')
        );
    }

    /**
     * @return DesignStudioCanvasViewsService
     */
    protected function getDesignStudioCanvasViewsService(): DesignStudioCanvasViewsService
    {
        return DDDService::instance()->getService(DesignStudioCanvasViewsService::class);
    }

    /**
     * @param DesignStudioComment $designStudioComment
     * @return string one line: id, author, flags, text
     */
    protected function formatComment(DesignStudioComment $designStudioComment): string
    {
        $flags = [];
        if ($designStudioComment->isThreadRoot()) {
            $flags[] = ($designStudioComment->isResolved ?? false) ? 'resolved' : 'open';
            if ($designStudioComment->isDecision ?? false) {
                $flags[] = 'decision';
            }
            if (($designStudioComment->x ?? null) !== null) {
                $flags[] = 'at ' . round((float)$designStudioComment->x) . ',' . round((float)($designStudioComment->y ?? 0));
            }
        }
        $createdTime = $designStudioComment->changeHistory->createdTime ?? null;
        return sprintf(
            '%s#%d %s (%s%s)%s: %s',
            $designStudioComment->isThreadRoot() ? '' : '   ↳ ',
            $designStudioComment->id,
            $designStudioComment->authorName ?? 'unknown',
            strtolower((string)($designStudioComment->authorKind ?? '')),
            $createdTime ? ', ' . $createdTime->format('Y-m-d H:i') : '',
            $flags ? ' [' . implode(', ', $flags) . ']' : '',
            str_replace("\n", "\n      ", (string)$designStudioComment->text)
        );
    }
}
