<?php

namespace App\Command;

use App\Controller\IndexController;
use DateTimeImmutable;
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
    public function __construct(
        readonly private IndexController $indexController,
        private readonly ServiceProviderInterface $languageProvider,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly string $assetVersion,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $docsDirectoryDestination = __DIR__.'/../../docs';
        $assetDirectorySource = __DIR__.'/../../public/assets';
        $date = (new DateTimeImmutable())->format('Ymd');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($docsDirectoryDestination)) {
            $io->error('/docs directory does not exist.');

            return Command::FAILURE;
        }

        // Assets
        $assets = (new Finder())->in($assetDirectorySource);
        $assetDirectoryDestination = $docsDirectoryDestination.'/'.$this->assetVersion;
        $filesystem->mkdir($assetDirectoryDestination);
        foreach ($assets as $asset) {
            $io->info('Copying '.$asset->getFilename());
            $filesystem->copy($asset->getRealPath(), $assetDirectoryDestination.'/'.$asset->getFilename());
        }

        // Index
        $io->info('Generating index.html ...');
        $response = $this->indexController->index($this->assetVersion);
        if (200 !== $response->getStatusCode()) {
            $io->error('Something went wrong.');

            return Command::FAILURE;
        }
        file_put_contents($docsDirectoryDestination.'/index.html', $response->getContent());

        // Puzzle of the day for each lang
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
                $docsDirectoryDestination.'/'.$lang,
                $docsDirectoryDestination.'/'.$lang.'/'.$date,
            ]);
            file_put_contents($docsDirectoryDestination.'/'.$lang.'/index.html', $response->getContent());
            file_put_contents($docsDirectoryDestination.'/'.$lang.'/'.$date.'/index.html', $response->getContent());
        }

        return Command::SUCCESS;
    }
}
