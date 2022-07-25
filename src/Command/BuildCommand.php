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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $docsDirectory = __DIR__.'/../../docs';
        $assetDirectory = __DIR__.'/../../public';
        $date = (new DateTimeImmutable())->format('Ymd');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($docsDirectory)) {
            $io->error('/docs directory does not exist.');

            return Command::FAILURE;
        }

        // Assets
        $assets = ['index.css', 'index.js', 'sha1.min.js'];
        foreach ($assets as $asset) {
            $file = $assetDirectory.'/'.$asset;
            if (!$filesystem->exists($file)) {
                $io->error($asset.' asset does not exist.');

                return Command::FAILURE;
            }

            $io->info('Copying '.$asset);
            $filesystem->copy($file, $docsDirectory.'/'.$asset);
        }

        // Index
        $io->info('Generating index.html ...');
        $response = $this->indexController->index();
        if (200 !== $response->getStatusCode()) {
            $io->error('Something went wrong.');

            return Command::FAILURE;
        }
        file_put_contents($docsDirectory.'/index.html', $response->getContent());

        // Puzzle of the day for each lang
        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            $io->info('Generating puzzle for '.$lang.' ...');

            $this->localeSwitcher->setLocale($lang);

            $request = new Request();
            $request->setLocale($lang);

            $response = $this->indexController->wikireveal($request);
            if (200 !== $response->getStatusCode()) {
                $io->error('Something went wrong.');

                return Command::FAILURE;
            }

            $filesystem->mkdir([
                $docsDirectory.'/'.$lang,
                $docsDirectory.'/'.$lang.'/'.$date,
            ]);
            file_put_contents($docsDirectory.'/'.$lang.'/index.html', $response->getContent());
            file_put_contents($docsDirectory.'/'.$lang.'/'.$date.'/index.html', $response->getContent());
        }

        return Command::SUCCESS;
    }
}
