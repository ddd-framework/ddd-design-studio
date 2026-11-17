<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Entities\Directory\DesignDirectoryBoard;
use DDD\Domain\DesignStudio\Entities\Thumbnails\RenderRequests\Pictures\DesignStudioThumbnailRenderPicture;
use DDD\Domain\DesignStudio\Services\DesignDirectoryReaderService;
use DDD\Domain\DesignStudio\Services\DesignStudioThumbnailRenderPicturesService;
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
            ->addOption('render', null, InputOption::VALUE_NONE, 'Requests the missing thumbnails, one message per picture (after a failed or incomplete request: only the pictures still missing)');
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
        /** @var DesignStudioThumbnailRenderRequestsService $designStudioThumbnailRenderRequestsService */
        $designStudioThumbnailRenderRequestsService = DDDService::instance()->getService(DesignStudioThumbnailRenderRequestsService::class);
        $themeReference = $input->getOption('theme') ?? ($canvasDefinition->referenceTheme ?? null);
        $themeStyle = $designThemeProviderService->getThemeStyleForReference($themeReference);
        $themeHash = DesignThemeProviderService::computeThemeHash($themeStyle);
        if (!$designThemeProviderService->isThemeStyleStable($themeReference, $themeStyle)) {
            $output->writeln(sprintf(
                '<comment>Warning: %s (theme %s): every call names another look, so no thumbnail can match it and no render request is filed. '
                . 'Make the theme provider answer the same <style> element for the same reference (no timestamps or random parts in it).</comment>',
                DesignThemeProviderService::THEME_STYLE_UNSTABLE_ERROR,
                $themeReference ?? '-'
            ));
        }
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
            $designStudioThumbnailRenderRequestsService->requestRendering($canvasDefinition, $themeReference, [], $this->getCliAccount());
        }
        $renderRequest = $designStudioThumbnailRenderRequestsService->findLatestRequest($canvasDefinition, $themeHash);
        if ($renderRequest) {
            /** @var DesignStudioThumbnailRenderPicturesService $designStudioThumbnailRenderPicturesService */
            $designStudioThumbnailRenderPicturesService = DDDService::instance()->getService(DesignStudioThumbnailRenderPicturesService::class);
            // the stall check: a picture no consumer took is dispatched again (once)
            $redispatchedCount = $designStudioThumbnailRenderRequestsService->redispatchUnconsumedPictures($renderRequest);
            $designStudioThumbnailRenderPictureCounts = $designStudioThumbnailRenderPicturesService->findPictureCounts($renderRequest);
            $output->writeln(sprintf(
                'render request #%d: %s, %d of %d pictures done, %d failed, %d skipped, %d queued%s',
                $renderRequest->id,
                $renderRequest->status,
                $designStudioThumbnailRenderPictureCounts->doneCount,
                $designStudioThumbnailRenderPictureCounts->getTotalCount(),
                $designStudioThumbnailRenderPictureCounts->failedCount,
                $designStudioThumbnailRenderPictureCounts->skippedCount,
                $designStudioThumbnailRenderPictureCounts->queuedCount,
                ($renderRequest->lastError ?? null) ? " — $renderRequest->lastError" : ''
            ));
            if ($redispatchedCount > 0) {
                $output->writeln(sprintf(
                    '<comment>  %d picture(s) had no try %d s after the request and were dispatched again: is a consumer of render_design_studio_thumbnails running?</comment>',
                    $redispatchedCount,
                    DesignStudioThumbnailRenderRequestsService::UNCONSUMED_PICTURE_AFTER_SECONDS
                ));
            }
            $designStudioThumbnailRenderPictures = $designStudioThumbnailRenderPicturesService->findPictures($renderRequest);
            $picturesWaitingForRetry = $designStudioThumbnailRenderPictures->getPicturesWaitingForRetry(time());
            if ($picturesWaitingForRetry->count()) {
                $output->writeln(sprintf(
                    '  %d picture(s) waiting for a retry until %s',
                    $picturesWaitingForRetry->count(),
                    (string)$picturesWaitingForRetry->getLatestNextAttemptAt()
                ));
            }
            $hasPictureRows = false;
            foreach ($designStudioThumbnailRenderPictures->getElements() as $designStudioThumbnailRenderPicture) {
                $hasPictureRows = true;
                if (!in_array($designStudioThumbnailRenderPicture->status, [DesignStudioThumbnailRenderPicture::STATUS_QUEUED, DesignStudioThumbnailRenderPicture::STATUS_FAILED], true)) {
                    continue;
                }
                $output->writeln('  ' . static::describePicture($designStudioThumbnailRenderPicture));
            }
            if (!$hasPictureRows) {
                // a request of 0.8 or earlier: its failures are kept on the request only
                foreach ($renderRequest->getFailedBoards()->getElements() as $designStudioThumbnailRenderFailure) {
                    $output->writeln("  failed: $designStudioThumbnailRenderFailure->renderTargetKey — $designStudioThumbnailRenderFailure->error");
                }
            }
        }
        return Command::SUCCESS;
    }

    /**
     * @param DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture a QUEUED or FAILED picture
     * @return string `queued: a.html — try 2 of 5 at 2026-11-17 10:00:30 — <last error>` /
     *                `failed: a.html — after 5 tries — <reason>`
     */
    public static function describePicture(DesignStudioThumbnailRenderPicture $designStudioThumbnailRenderPicture): string
    {
        $attempts = (int)($designStudioThumbnailRenderPicture->attempts ?? 0);
        $lastError = ($designStudioThumbnailRenderPicture->lastError ?? null) ? ' — ' . $designStudioThumbnailRenderPicture->lastError : '';
        if ($designStudioThumbnailRenderPicture->status === DesignStudioThumbnailRenderPicture::STATUS_FAILED) {
            return sprintf('failed: %s — after %d %s%s', $designStudioThumbnailRenderPicture->renderTargetKey, max(1, $attempts), max(1, $attempts) === 1 ? 'try' : 'tries', $lastError);
        }
        $nextAttemptAt = $designStudioThumbnailRenderPicture->nextAttemptAt ?? null;
        return sprintf(
            'queued: %s — try %d of %d%s%s',
            $designStudioThumbnailRenderPicture->renderTargetKey,
            $attempts + 1,
            DesignStudioThumbnailRenderRequestsService::MAX_PICTURE_ATTEMPTS,
            $nextAttemptAt !== null ? ' at ' . $nextAttemptAt : ($attempts === 0 ? ', not taken by a consumer yet' : ''),
            $lastError
        );
    }
}
