<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Services\DesignStudioBoardStatusesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:status',
    description: 'Sets the review status of a design studio board (one of the configured statuses)'
)]
class DesignStudioStatus extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addCanvasArguments();
        $this->addArgument('board', InputArgument::REQUIRED, 'File name of the board')
            ->addArgument('status', InputArgument::REQUIRED, 'Status key (config DesignStudio.statuses)')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Name shown as who set the status', 'Agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $canvasDefinition = $this->getCanvasDefinition($input);
        $designDirectoryBoard = $this->getDesignStudioCanvasViewsService()->getDirectoryBoard($canvasDefinition, (string)$input->getArgument('board'));
        /** @var DesignStudioBoardStatusesService $designStudioBoardStatusesService */
        $designStudioBoardStatusesService = DDDService::instance()->getService(DesignStudioBoardStatusesService::class);
        $designStudioBoardStatus = $designStudioBoardStatusesService->setStatus(
            $canvasDefinition,
            $designDirectoryBoard,
            (string)$input->getArgument('status'),
            (string)$input->getOption('as')
        );
        $output->writeln("$designStudioBoardStatus->boardKey: $designStudioBoardStatus->status (version " . substr((string)$designStudioBoardStatus->contentHash, 0, 7) . ')');
        return Command::SUCCESS;
    }
}
