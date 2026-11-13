<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderRequestsService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailsService;
use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:thumbnails',
    description: 'Reports how many boards of a design studio canvas have a thumbnail for a theme and, with --render, asks the renderer for the missing ones'
)]
class DesignStudioThumbnails extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addCanvasArguments();
        $this->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme reference `<themeId>[:<mode>]`; omitted = the reference theme of the canvas')
            ->addOption('render', null, InputOption::VALUE_NONE, 'Requests the missing thumbnails (the render worker makes them)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $canvasDefinition = $this->getCanvasDefinition($input);
        /** @var DesignStudioThumbnailsService $designStudioThumbnailsService */
        $designStudioThumbnailsService = DDDService::instance()->getService(DesignStudioThumbnailsService::class);
        if (!$designStudioThumbnailsService->isRendererEnabled()) {
            $output->writeln('No renderer is configured (config DesignStudio.renderer): the studio shows the boards live.');
            return Command::SUCCESS;
        }
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        /** @var DesignDirectoryReaderService $designDirectoryReaderService */
        $designDirectoryReaderService = DDDService::instance()->getService(DesignDirectoryReaderService::class);
        /** @var DesignStudioThumbnailRenderRequestsService $renderRequestsService */
        $renderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
        $themeReference = $input->getOption('theme') ?? ($canvasDefinition->referenceTheme ?? null);
        $themeHash = DesignThemeProviderService::computeThemeHash($designThemeProviderService->getThemeStyleForReference($themeReference));
        $designDirectoryBoards = $designDirectoryReaderService->readCanvas($canvasDefinition)->boards;
        $boardsWithoutThumbnail = $designStudioThumbnailsService->findBoardsWithoutThumbnail($designDirectoryBoards, $themeHash);
        // a responsive board has one picture per device frame: count pictures, name boards and frames
        $renderTargets = $designStudioThumbnailsService->expandRenderTargets($designDirectoryBoards);
        $frameCount = count(array_filter(
            $renderTargets->getElements(),
            static fn(DesignDirectoryBoard $renderTarget): bool => $renderTarget->frameDeviceKey !== null
        ));
        $output->writeln(sprintf(
            '%d of %d pictures have a thumbnail (%d boards%s; renderer %s, theme hash %s)',
            $renderTargets->count() - $boardsWithoutThumbnail->count(),
            $renderTargets->count(),
            $designDirectoryBoards->count(),
            $frameCount > 0 ? ", $frameCount device frames of responsive boards" : '',
            $designStudioThumbnailsService->getRendererVersion(),
            substr($themeHash, 0, 10)
        ));
        if ($input->getOption('render') && $boardsWithoutThumbnail->count()) {
            $renderRequestsService->requestRendering($canvasDefinition, $themeReference, [], $this->getCliAccount());
        }
        $renderRequest = $renderRequestsService->findLatestRequest($canvasDefinition, $themeHash);
        if ($renderRequest) {
            $output->writeln("render request #$renderRequest->id: $renderRequest->status, " . ($renderRequest->attempts ?? 0) . ' attempt(s)'
                . (($renderRequest->lastError ?? null) ? " — $renderRequest->lastError" : ''));
        }
        return Command::SUCCESS;
    }
}
