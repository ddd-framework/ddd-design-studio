<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:comments-poll',
    description: 'Prints comments of a design studio canvas newer than a comment id (optionally waits for them) and the id to continue from'
)]
class DesignStudioCommentsPoll extends DesignStudioCommand
{
    /** @var int Seconds between two looks while waiting */
    public const int POLL_INTERVAL_SECONDS = 5;

    protected function configure(): void
    {
        $this->addCanvasArguments();
        $this->addOption('sinceId', null, InputOption::VALUE_REQUIRED, 'Only comments with a larger id', '0')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for a new comment before giving up', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $canvasDefinition = $this->getCanvasDefinition($input);
        /** @var DesignStudioCommentsService $designStudioCommentsService */
        $designStudioCommentsService = DDDService::instance()->getService(DesignStudioCommentsService::class);
        $sinceId = (int)$input->getOption('sinceId');
        $deadline = time() + max(0, (int)$input->getOption('wait'));
        do {
            $designStudioComments = $designStudioCommentsService->findForCanvas((string)$canvasDefinition->projectKey, (string)$canvasDefinition->canvasKey, false, $sinceId);
            if ($designStudioComments->count() || time() >= $deadline) {
                break;
            }
            sleep(static::POLL_INTERVAL_SECONDS);
        } while (true);
        $newestId = $sinceId;
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            $output->writeln("$designStudioComment->boardKey  " . $this->formatComment($designStudioComment));
            $newestId = max($newestId, (int)$designStudioComment->id);
        }
        $output->writeln("sinceId=$newestId");
        return Command::SUCCESS;
    }
}
