<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\DesignStudio;

use DDD\Domain\DesignStudio\Services\DesignThemeProviderService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:design-studio:theme',
    description: 'Prints the style element of a design studio theme (for local board renders) or lists the themes'
)]
class DesignStudioTheme extends DesignStudioCommand
{
    protected function configure(): void
    {
        $this->addArgument('theme', InputArgument::OPTIONAL, 'Theme reference `<themeId>[:<mode>]` (mode light or dark); omitted = list the themes')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Narrows the list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->authenticate();
        /** @var DesignThemeProviderService $designThemeProviderService */
        $designThemeProviderService = DDDService::instance()->getService(DesignThemeProviderService::class);
        $themeReference = $input->getArgument('theme');
        if (!$themeReference) {
            foreach ($designThemeProviderService->findThemes($input->getOption('search'))->getElements() as $designTheme) {
                $output->writeln("$designTheme->themeId  $designTheme->label" . ($designTheme->supportsDark ? '  (light, dark)' : '  (light)'));
            }
            return Command::SUCCESS;
        }
        $themeStyle = $designThemeProviderService->getThemeStyleForReference((string)$themeReference);
        if ($themeStyle === null) {
            $output->writeln("<error>Unknown theme $themeReference</error>");
            return Command::FAILURE;
        }
        $output->writeln($themeStyle);
        return Command::SUCCESS;
    }
}
