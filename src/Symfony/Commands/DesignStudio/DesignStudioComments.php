<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioComment;
use DDD\Domain\DesignStudio\Entities\Comments\DesignStudioCommentViewport;
use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:comments',
    description: 'Lists the comment threads of a design studio canvas, starts a thread, replies, resolves, reopens, keeps or deletes one (for agents)'
)]
class DesignStudioComments extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addCanvasArguments();
        $this->addOption('open', null, InputOption::VALUE_NONE, 'List only threads that are not resolved')
            ->addOption('board', null, InputOption::VALUE_REQUIRED, 'List only this board; with --new: the board the thread is started on')
            ->addOption('sinceId', null, InputOption::VALUE_REQUIRED, 'List only comments with a larger id')
            ->addOption('new', null, InputOption::VALUE_REQUIRED, 'Starts a thread with this text on --board')
            ->addOption('x', null, InputOption::VALUE_REQUIRED, 'With --new: pin position from the left edge in board pixels')
            ->addOption('y', null, InputOption::VALUE_REQUIRED, 'With --new: pin position from the top edge in board pixels')
            ->addOption('viewport', null, InputOption::VALUE_REQUIRED, 'With --new on a responsive board: the viewport the pin position is in, <width>x<height> (e.g. 393x852)')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'With --new and --viewport: the device preset key (e.g. iphone-16)')
            ->addOption('decision', null, InputOption::VALUE_NONE, 'With --new: marks the thread as a decision')
            ->addOption('reply', null, InputOption::VALUE_REQUIRED, 'Id of a comment: replies with --text')
            ->addOption('resolve', null, InputOption::VALUE_REQUIRED, 'Id of a comment: resolves its thread (with --text: after a last reply)')
            ->addOption('reopen', null, InputOption::VALUE_REQUIRED, 'Id of a comment: reopens its thread')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Id of a comment: its thread expires anew from now')
            ->addOption('delete', null, InputOption::VALUE_REQUIRED, 'Id of a comment: deletes it (a thread root with its replies)')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Text of the reply')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Author name shown in the studio (the comment is marked as written by an agent)', 'Agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $canvasDefinition = $this->getCanvasDefinition($input);
        /** @var DesignStudioCommentsService $designStudioCommentsService */
        $designStudioCommentsService = DDDService::instance()->getService(DesignStudioCommentsService::class);
        $authorName = (string)$input->getOption('as');
        $authorKind = DesignStudioComment::AUTHOR_KIND_AGENT;
        $text = $input->getOption('text');

        if ($input->getOption('new') !== null) {
            if (!$input->getOption('board')) {
                $output->writeln('<error>--new needs --board</error>');
                return Command::FAILURE;
            }
            $designDirectoryBoard = $this->getDesignStudioCanvasViewsService()->getDirectoryBoard($canvasDefinition, (string)$input->getOption('board'));
            $designStudioComment = $designStudioCommentsService->createThread(
                $canvasDefinition,
                $designDirectoryBoard,
                (string)$input->getOption('new'),
                $input->getOption('x') !== null ? (float)$input->getOption('x') : null,
                $input->getOption('y') !== null ? (float)$input->getOption('y') : null,
                (bool)$input->getOption('decision'),
                $authorKind,
                $authorName,
                $this->parseViewportOption($input)
            );
            $output->writeln($this->formatComment($designStudioComment));
            return Command::SUCCESS;
        }
        foreach (['reply', 'resolve', 'reopen', 'keep', 'delete'] as $action) {
            $commentId = $input->getOption($action);
            if ($commentId === null) {
                continue;
            }
            $designStudioComment = $designStudioCommentsService->find((int)$commentId);
            if (!$designStudioComment || $designStudioComment->projectKey !== $canvasDefinition->projectKey || $designStudioComment->canvasKey !== $canvasDefinition->canvasKey) {
                $output->writeln("<error>Comment #$commentId is not on this canvas</error>");
                return Command::FAILURE;
            }
            if ($action === 'reply' && ($text === null || trim((string)$text) === '')) {
                $output->writeln('<error>--reply needs --text</error>');
                return Command::FAILURE;
            }
            if ($action === 'delete') {
                $designStudioCommentsService->deleteComment($designStudioComment);
                $output->writeln("deleted #$commentId");
                return Command::SUCCESS;
            }
            $result = match ($action) {
                'reply' => $designStudioCommentsService->reply($designStudioComment, (string)$text, $authorKind, $authorName),
                'resolve' => $designStudioCommentsService->resolveThread($designStudioComment, $text !== null ? (string)$text : null, $authorKind, $authorName),
                'reopen' => $designStudioCommentsService->reopenThread($designStudioComment),
                'keep' => $designStudioCommentsService->keepThread($designStudioComment),
            };
            $output->writeln("$action: " . $this->formatComment($result));
            return Command::SUCCESS;
        }

        $designStudioComments = $designStudioCommentsService->findForCanvas(
            (string)$canvasDefinition->projectKey,
            (string)$canvasDefinition->canvasKey,
            (bool)$input->getOption('open'),
            $input->getOption('sinceId') !== null ? (int)$input->getOption('sinceId') : null
        );
        $boardKeyFilter = $input->getOption('board');
        $currentBoardKey = null;
        $count = 0;
        // grouped by board, threads in the order they were started, replies below their root
        $commentsByRoot = [];
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            if ($boardKeyFilter && $designStudioComment->boardKey !== $boardKeyFilter) {
                continue;
            }
            $commentsByRoot[$designStudioComment->boardKey][(int)($designStudioComment->parentDesignStudioCommentId ?? $designStudioComment->id)][] = $designStudioComment;
        }
        ksort($commentsByRoot);
        foreach ($commentsByRoot as $boardKey => $threads) {
            foreach ($threads as $thread) {
                foreach ($thread as $designStudioComment) {
                    if ($currentBoardKey !== $boardKey) {
                        $output->writeln($boardKey);
                        $currentBoardKey = $boardKey;
                    }
                    $output->writeln('  ' . $this->formatComment($designStudioComment));
                    $count++;
                }
            }
        }
        $output->writeln("$count comment(s)");
        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return DesignStudioCommentViewport|null the viewport of --viewport `<width>x<height>` and --device, null without
     */
    protected function parseViewportOption(InputInterface $input): ?DesignStudioCommentViewport
    {
        if (!preg_match('/^\s*(\d+)\s*[xX×]\s*(\d+)\s*$/u', (string)$input->getOption('viewport'), $viewportMatch)) {
            return null;
        }
        return DesignStudioCommentViewport::fromWidthHeightAndDeviceKey((int)$viewportMatch[1], (int)$viewportMatch[2], $input->getOption('device'));
    }
}
