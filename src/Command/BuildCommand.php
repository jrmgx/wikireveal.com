<?php

namespace App\Command;

use App\Controller\IndexController;
use App\Language\LanguageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[AsCommand(
    name: 'app:build',
    description: 'Build all pages for this day',
)]
class BuildCommand extends Command
{
    /**
     * @param ServiceProviderInterface<LanguageInterface> $languageProvider
     */
    public function __construct(
        private readonly IndexController $indexController,
        private readonly ServiceProviderInterface $languageProvider,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly string $assetVersion,
        private readonly string $docsDirectoryDestination,
        private readonly string $assetsDirectorySource,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $date = (new \DateTimeImmutable())->format('Ymd');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($this->docsDirectoryDestination)) {
            $io->error('/docs directory does not exist.');

            return Command::FAILURE;
        }

        // Assets
        $assets = (new Finder())->in($this->assetsDirectorySource);
        $assetDirectoryDestination = $this->docsDirectoryDestination.'/'.$this->assetVersion;
        $filesystem->mkdir($assetDirectoryDestination);
        foreach ($assets as $asset) {
            $io->info('Copying '.$asset->getFilename());
            $filesystem->copy(
                $asset->getRealPath(),
                $assetDirectoryDestination.'/'.$asset->getFilename(),
                true
            );
        }

        // Index
        $io->info('Generating index.html ...');
        $response = $this->indexController->index($this->assetVersion);
        if (200 !== $response->getStatusCode()) {
            $io->error('Something went wrong.');

            return Command::FAILURE;
        }
        file_put_contents(
            $this->docsDirectoryDestination.'/index.html',
            $response->getContent()
        );

        // Puzzle of the day + archive for each lang
        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            $io->info('Generating puzzle for '.$lang.' ...');

            $this->localeSwitcher->setLocale($lang);

            $request = new Request();
            $request->setLocale($lang);

            $response = $this->indexController->wikireveal($request, $this->assetVersion);
            if (200 !== $response->getStatusCode()) {
                $io->error('Something went wrong.');

                return Command::FAILURE;
            }

            $filesystem->mkdir([
                $this->docsDirectoryDestination.'/'.$lang,
                $this->docsDirectoryDestination.'/'.$lang.'/'.$date,
            ]);
            file_put_contents(
                $this->docsDirectoryDestination.'/'.$lang.'/index.html',
                $response->getContent()
            );
            file_put_contents(
                $this->docsDirectoryDestination.'/'.$lang.'/'.$date.'/index.html',
                $response->getContent()
            );

            $io->info('Generating archive for '.$lang.' ...');

            $response = $this->indexController->archive($request, $this->assetVersion);
            if (200 !== $response->getStatusCode()) {
                $io->error('Something went wrong.');

                return Command::FAILURE;
            }

            $filesystem->mkdir([
                $this->docsDirectoryDestination.'/'.$lang.'/archive',
            ]);
            file_put_contents(
                $this->docsDirectoryDestination.'/'.$lang.'/archive/index.html',
                $response->getContent()
            );
        }

        return Command::SUCCESS;
    }
}
