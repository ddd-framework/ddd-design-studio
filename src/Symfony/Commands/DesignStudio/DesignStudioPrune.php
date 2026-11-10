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
    name: 'app:design-studio:prune',
    description: 'Deletes design studio comment threads that are idle longer than the configured time (the studio does the same once a day by itself) and lists the ones expiring soon'
)]
class DesignStudioPrune extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Only report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        /** @var DesignStudioCommentsService $designStudioCommentsService */
        $designStudioCommentsService = DDDService::instance()->getService(DesignStudioCommentsService::class);
        $isDryRun = (bool)$input->getOption('dryRun');
        $expiredThreads = $designStudioCommentsService->pruneExpiredThreads($isDryRun);
        $output->writeln(($isDryRun ? 'would delete ' : 'deleted ') . $expiredThreads->count() . ' thread(s)');
        foreach ($expiredThreads->getElements() as $designStudioComment) {
            $output->writeln("  $designStudioComment->projectKey/$designStudioComment->canvasKey/$designStudioComment->boardKey " . $this->formatComment($designStudioComment));
        }
        $expiringThreads = $designStudioCommentsService->findExpiringThreads();
        $output->writeln($expiringThreads->count() . ' thread(s) expiring soon');
        foreach ($expiringThreads->getElements() as $designStudioComment) {
            $output->writeln("  $designStudioComment->projectKey/$designStudioComment->canvasKey/$designStudioComment->boardKey " . $this->formatComment($designStudioComment));
        }
        return Command::SUCCESS;
    }
}
