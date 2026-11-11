<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Services\DesignBoardCodesService;
use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:show',
    description: 'Shows design studio projects, a canvas with its sections and boards (version, status, open threads), or one board with its threads'
)]
class DesignStudioShow extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::OPTIONAL, 'Key of the design project; omitted = the overview')
            ->addArgument('canvas', InputArgument::OPTIONAL, 'Key of the canvas within the project')
            ->addOption('board', null, InputOption::VALUE_REQUIRED, 'File name or code of one board (`APP ONB-W2`, on its canvas also `W2`): prints the board with its comment threads; with a full code project and canvas may be left out')
            ->addOption('open', null, InputOption::VALUE_NONE, 'Only boards with open threads');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $designStudioCanvasViewsService = $this->getDesignStudioCanvasViewsService();
        $boardKey = $input->getOption('board');
        $canvasDefinition = null;
        if ($boardKey && (!$input->getArgument('project') || !$input->getArgument('canvas'))) {
            /** @var DesignBoardCodesService $designBoardCodesService */
            $designBoardCodesService = DDDService::instance()->getService(DesignBoardCodesService::class);
            $found = $designBoardCodesService->findBoardByCode((string)$boardKey);
            if ($found === null) {
                $output->writeln("<error>No board (or more than one) has the code $boardKey</error>");
                return Command::FAILURE;
            }
            [$canvasDefinition, $designDirectoryBoard] = $found;
            $boardKey = $designDirectoryBoard->boardKey;
        } elseif (!$input->getArgument('project') || !$input->getArgument('canvas')) {
            $designStudioOverview = $designStudioCanvasViewsService->getOverview();
            foreach ($designStudioOverview->projects->getElements() as $designStudioProjectSummary) {
                $output->writeln("$designStudioProjectSummary->projectKey — $designStudioProjectSummary->label");
                foreach ($designStudioProjectSummary->canvases->getElements() as $designStudioCanvasSummary) {
                    $output->writeln(sprintf(
                        '  %s%s — %s: %d boards, %d open threads, %d decisions',
                        $designStudioCanvasSummary->canvasKey,
                        $designStudioCanvasSummary->code !== null ? " [$designStudioCanvasSummary->code]" : '',
                        $designStudioCanvasSummary->label,
                        $designStudioCanvasSummary->boardCount,
                        $designStudioCanvasSummary->openThreadCount,
                        $designStudioCanvasSummary->decisionCount
                    ));
                }
            }
            $output->writeln('statuses: ' . implode(', ', $designStudioOverview->statuses)
                . " · threads idle for $designStudioOverview->threadIdleDays days are deleted ($designStudioOverview->expiringThreadCount expiring soon)");
            return Command::SUCCESS;
        }
        $canvasDefinition ??= $this->getCanvasDefinition($input);
        $designStudioCanvasView = $designStudioCanvasViewsService->getCanvasView($canvasDefinition, null, $this->getCliAccount());
        if ($boardKey) {
            // a code on this canvas (full, without the project part, or the board part alone) names the board as well
            foreach ($designStudioCanvasView->boards->getElements() as $designStudioBoardView) {
                if ($designStudioBoardView->boardKey !== $boardKey && $designStudioBoardView->code !== null
                    && DesignBoardCodesService::codeMatches((string)$boardKey, $designStudioBoardView->code)) {
                    $boardKey = $designStudioBoardView->boardKey;
                    break;
                }
            }
            /** @var DesignStudioCommentsService $designStudioCommentsService */
            $designStudioCommentsService = DDDService::instance()->getService(DesignStudioCommentsService::class);
            foreach ($designStudioCanvasView->boards->getElements() as $designStudioBoardView) {
                if ($designStudioBoardView->boardKey !== $boardKey) {
                    continue;
                }
                $output->writeln(($designStudioBoardView->code !== null ? "$designStudioBoardView->code · " : '')
                    . "$designStudioBoardView->boardKey — $designStudioBoardView->title");
                $output->writeln("  canvas $canvasDefinition->projectKey/$canvasDefinition->canvasKey");
                $output->writeln("  version $designStudioBoardView->contentHash · {$designStudioBoardView->width}×$designStudioBoardView->height · status $designStudioBoardView->status"
                    . ($designStudioBoardView->hasChangedSinceStatus ? ' (board changed since)' : ''));
                foreach ($designStudioCommentsService->findForCanvas((string)$canvasDefinition->projectKey, (string)$canvasDefinition->canvasKey)->getElements() as $designStudioComment) {
                    if ($designStudioComment->boardKey === $boardKey) {
                        $output->writeln('  ' . $this->formatComment($designStudioComment)
                            . ($designStudioComment->isThreadRoot() && $designStudioComment->hasBoardChangedSince($designStudioBoardView->contentHash) ? '  (board changed since)' : ''));
                    }
                }
                return Command::SUCCESS;
            }
            $output->writeln("<error>Board $boardKey is not in this checkout's canvas</error>");
            return Command::FAILURE;
        }
        $output->writeln("$designStudioCanvasView->projectKey / $designStudioCanvasView->canvasKey"
            . ($designStudioCanvasView->code !== null ? " [$designStudioCanvasView->code]" : '')
            . " — $designStudioCanvasView->label (revision " . substr((string)$designStudioCanvasView->revision, 0, 10) . ')');
        $boardsBySection = [];
        foreach ($designStudioCanvasView->boards->getElements() as $designStudioBoardView) {
            $boardsBySection[$designStudioBoardView->sectionKey ?? ''][] = $designStudioBoardView;
        }
        $sectionTitles = ['' => '(no section)'];
        foreach ($designStudioCanvasView->sections->getElements() as $designDirectorySection) {
            $sectionTitles[(string)$designDirectorySection->sectionKey] = $designDirectorySection->title ?? $designDirectorySection->sectionKey;
        }
        foreach ($sectionTitles as $sectionKey => $sectionTitle) {
            $lines = [];
            foreach ($boardsBySection[$sectionKey] ?? [] as $designStudioBoardView) {
                if ($input->getOption('open') && !$designStudioBoardView->openThreadCount) {
                    continue;
                }
                $lines[] = sprintf(
                    '  %-14s %-44s %s  %-10s %s%s',
                    $designStudioBoardView->code ?? '',
                    $designStudioBoardView->boardKey,
                    substr((string)$designStudioBoardView->contentHash, 0, 7),
                    $designStudioBoardView->status,
                    $designStudioBoardView->openThreadCount ? $designStudioBoardView->openThreadCount . ' open  ' : '',
                    $designStudioBoardView->title
                );
            }
            if ($lines) {
                $output->writeln($sectionTitle);
                $output->writeln($lines);
            }
        }
        foreach ($designStudioCanvasView->warnings as $warning) {
            $output->writeln("<comment>warning: $warning</comment>");
        }
        return Command::SUCCESS;
    }
}
