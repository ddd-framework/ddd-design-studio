<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Services\DesignStudioCommentsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:decisions',
    description: 'Prints the decisions log (decision-flagged comment threads) of a design studio canvas as Markdown'
)]
class DesignStudioDecisions extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addCanvasArguments();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        $canvasDefinition = $this->getCanvasDefinition($input);
        /** @var DesignStudioCommentsService $designStudioCommentsService */
        $designStudioCommentsService = DDDService::instance()->getService(DesignStudioCommentsService::class);
        $designStudioComments = $designStudioCommentsService->findForCanvas((string)$canvasDefinition->projectKey, (string)$canvasDefinition->canvasKey);
        $decisionRootIds = [];
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            if ($designStudioComment->isThreadRoot() && ($designStudioComment->isDecision ?? false)) {
                $decisionRootIds[(int)$designStudioComment->id] = true;
            }
        }
        $output->writeln("# Decisions — $canvasDefinition->projectKey / $canvasDefinition->canvasKey");
        foreach ($designStudioComments->getElements() as $designStudioComment) {
            if ($designStudioComment->isThreadRoot() && isset($decisionRootIds[(int)$designStudioComment->id])) {
                $output->writeln('');
                $createdTime = $designStudioComment->changeHistory->createdTime ?? null;
                $output->writeln("## $designStudioComment->boardKey — #$designStudioComment->id, $designStudioComment->authorName"
                    . ($createdTime ? ', ' . $createdTime->format('Y-m-d') : '')
                    . ' (version ' . substr((string)$designStudioComment->contentHash, 0, 7) . ')');
                $output->writeln('');
                $output->writeln((string)$designStudioComment->text);
            } elseif (isset($decisionRootIds[(int)($designStudioComment->parentDesignStudioCommentId ?? 0)])) {
                $output->writeln('');
                $output->writeln("- $designStudioComment->authorName: " . str_replace("\n", ' ', (string)$designStudioComment->text));
            }
        }
        if (!$decisionRootIds) {
            $output->writeln('');
            $output->writeln('No decisions recorded.');
        }
        return Command::SUCCESS;
    }
}
