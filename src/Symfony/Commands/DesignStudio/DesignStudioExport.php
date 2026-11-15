<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Entities\Exports\DesignBoardExportRegion;
use DDD\Domain\DesignStudio\Services\DesignStudioBoardExportsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:export',
    description: 'Exports a board of a design studio canvas in a theme as SVG through the renderer, with its file size, the size of the same picture as WebP and the share of pixels that differ from the raster picture'
)]
class DesignStudioExport extends DesignStudioCommand
{
    /** @var string The only format so far */
    public const string FORMAT_SVG = 'svg';

    protected function configure(): void
    {
        $this->addCanvasArguments();
        $this->addArgument('board', InputArgument::REQUIRED, 'File name or code of the board (`APP ONB-W2`, on its canvas also `W2`)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: svg', self::FORMAT_SVG)
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme reference `<themeId>[:<mode>]`; omitted = the reference theme of the canvas, without one the board as authored')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'File the SVG is written to; omitted = `<board>[-<theme>][-<device>][-<region>].svg` in the current folder')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Only a part of the board: `x,y,width,height` in board pixels (e.g. the region of a design reference)')
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Device frame of a responsive board (device key); omitted = its first frame')
            ->addOption('raster-out', null, InputOption::VALUE_REQUIRED, 'Also write the raster picture of the same version (`.webp` or `.png` by the file extension)')
            ->addOption('comparison-out', null, InputOption::VALUE_REQUIRED, 'Also write the comparison picture (PNG: raster | SVG | differing pixels in red)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        if (strtolower((string)$input->getOption('format')) !== self::FORMAT_SVG) {
            throw new BadRequestException('Unknown format ' . $input->getOption('format') . ' (available: ' . self::FORMAT_SVG . ')');
        }
        $canvasDefinition = $this->getCanvasDefinition($input);
        /** @var DesignStudioBoardExportsService $designStudioBoardExportsService */
        $designStudioBoardExportsService = DDDService::instance()->getService(DesignStudioBoardExportsService::class);
        $designDirectoryBoard = $designStudioBoardExportsService->findBoard($canvasDefinition, (string)$input->getArgument('board'));
        $themeReference = $input->getOption('theme') ?? ($canvasDefinition->referenceTheme ?? null);
        $regionText = $input->getOption('region');
        $region = $regionText !== null ? DesignBoardExportRegion::fromRegionText((string)$regionText) : null;
        $designBoardVectorExport = $designStudioBoardExportsService->exportBoardAsSvg(
            $canvasDefinition,
            $designDirectoryBoard,
            $themeReference,
            $region,
            $input->getOption('device')
        );
        if ($designBoardVectorExport->failureReason !== null) {
            $output->writeln('<error>Export failed: ' . $designBoardVectorExport->failureReason
                . ($designBoardVectorExport->failureStatusCode === 404 ? ' (the renderer has no vector job: it needs renderer 1.2.0)' : '')
                . '</error>');
            return Command::FAILURE;
        }
        $outPath = (string)($input->getOption('out') ?? DesignStudioBoardExportsService::buildFileName(
            (string)$designDirectoryBoard->boardKey,
            $themeReference,
            $input->getOption('device'),
            $region
        ));
        $this->writeFile($outPath, (string)$designBoardVectorExport->svg);
        $svgByteSize = strlen((string)$designBoardVectorExport->svg);
        $webp = $designBoardVectorExport->png !== null ? $designStudioBoardExportsService->convertPngToWebp($designBoardVectorExport->png) : null;
        $output->writeln(sprintf(
            '%s: %s %s%s, %dx%d px%s',
            $outPath,
            $designDirectoryBoard->code ?? $designDirectoryBoard->boardKey,
            $themeReference !== null ? "in $themeReference" : 'as authored',
            $region !== null ? ', region ' . $region->toRegionText() : '',
            (int)$designBoardVectorExport->exportWidth,
            (int)$designBoardVectorExport->exportHeight,
            $input->getOption('device') !== null ? ', device ' . $input->getOption('device') : ''
        ));
        $output->writeln(sprintf(
            'size: SVG %s (gzip %s) · raster PNG %s%s',
            $this->formatByteSize($svgByteSize),
            $this->formatByteSize(strlen((string)gzencode((string)$designBoardVectorExport->svg, 9))),
            $this->formatByteSize(strlen((string)$designBoardVectorExport->png)),
            $webp !== null ? ' · WebP q' . DesignStudioBoardExportsService::COMPARISON_WEBP_QUALITY . ' ' . $this->formatByteSize(strlen($webp)) : ''
        ));
        $output->writeln(sprintf(
            'differs from the raster picture in %.1f %% of the pixels (colour difference above 48); glass (backdrop-filter), blend modes and some filters are lost in SVG — see --comparison-out',
            100 * (float)$designBoardVectorExport->differingPixelRatio
        ));
        $rasterOutPath = $input->getOption('raster-out');
        if ($rasterOutPath !== null && $designBoardVectorExport->png !== null) {
            $isWebpPath = str_ends_with(strtolower((string)$rasterOutPath), '.webp');
            if ($isWebpPath && $webp === null) {
                throw new BadRequestException('The image library has no WebP: write the raster picture as .png');
            }
            $this->writeFile((string)$rasterOutPath, $isWebpPath ? (string)$webp : $designBoardVectorExport->png);
            $output->writeln("raster picture: $rasterOutPath");
        }
        $comparisonOutPath = $input->getOption('comparison-out');
        if ($comparisonOutPath !== null && $designBoardVectorExport->comparisonPng !== null) {
            $this->writeFile((string)$comparisonOutPath, $designBoardVectorExport->comparisonPng);
            $output->writeln("comparison picture: $comparisonOutPath");
        }
        return Command::SUCCESS;
    }

    /**
     * @param string $path
     * @param string $content
     * @return void
     * @throws BadRequestException
     */
    protected function writeFile(string $path, string $content): void
    {
        if (@file_put_contents($path, $content) === false) {
            throw new BadRequestException("Cannot write $path");
        }
    }

    /**
     * @param int $byteSize
     * @return string e.g. `224 KB`
     */
    protected function formatByteSize(int $byteSize): string
    {
        return $byteSize >= 1024 * 1024
            ? number_format($byteSize / 1024 / 1024, 1) . ' MB'
            : max(1, (int)round($byteSize / 1024)) . ' KB';
    }
}
