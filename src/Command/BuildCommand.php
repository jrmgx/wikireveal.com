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

        $this->removePuzzlesOlderThanThreeMonths($io, $filesystem);

        $returnCode = $this->backfillMissingPuzzles($io, $filesystem);

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

        // The idea is to be over resilient but still crash the CI job so the maintainer can check the log
        // Puzzle of the day + archive for each lang
        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            try {
                $io->info('Generating puzzle for '.$lang.' ...');

                $this->localeSwitcher->setLocale($lang);

                $request = new Request();
                $request->setLocale($lang);

                $response = $this->indexController->wikireveal($request, $this->assetVersion);
                if (200 !== $response->getStatusCode()) {
                    $io->error('Something went wrong.');

                    $returnCode = Command::FAILURE;
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

                    $returnCode = Command::FAILURE;
                }

                $filesystem->mkdir([
                    $this->docsDirectoryDestination.'/'.$lang.'/archive',
                ]);
                file_put_contents(
                    $this->docsDirectoryDestination.'/'.$lang.'/archive/index.html',
                    $response->getContent()
                );

                $io->info('Generating json archive for '.$lang.' ...');

                $response = $this->indexController->archive($request, $this->assetVersion, '.json');
                if (200 !== $response->getStatusCode()) {
                    $io->error('Something went wrong.');

                    $returnCode = Command::FAILURE;
                }

                file_put_contents(
                    $this->docsDirectoryDestination.'/'.$lang.'/archive/index.json',
                    $response->getContent()
                );
            } catch (\Exception $e) {
                $io->error($e->getMessage());

                $returnCode = Command::FAILURE;
            }
        }

        return $returnCode;
    }

    /**
     * Generates docs/{locale}/{Ymd}/ for each missing day within the last three months (through yesterday).
     */
    private function backfillMissingPuzzles(SymfonyStyle $io, Filesystem $filesystem): int
    {
        $returnCode = Command::SUCCESS;
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify('-3 months');
        $yesterday = $today->modify('-1 day');

        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            $this->localeSwitcher->setLocale($lang);

            $request = new Request();
            $request->setLocale($lang);

            for ($day = $cutoff; $day <= $yesterday; $day = $day->modify('+1 day')) {
                $date = $day->format('Ymd');
                $puzzleFile = $this->docsDirectoryDestination.'/'.$lang.'/'.$date.'/index.html';
                if ($filesystem->exists($puzzleFile)) {
                    continue;
                }

                $io->info(sprintf('Backfilling puzzle for %s on %s ...', $lang, $date));

                try {
                    $response = $this->indexController->wikireveal($request, $this->assetVersion, $day);
                    if (200 !== $response->getStatusCode()) {
                        $io->error('Something went wrong.');
                        $returnCode = Command::FAILURE;

                        continue;
                    }

                    $filesystem->mkdir($this->docsDirectoryDestination.'/'.$lang.'/'.$date);
                    file_put_contents($puzzleFile, $response->getContent());
                    usleep(500_000);
                } catch (\Exception $e) {
                    $io->error($e->getMessage());
                    $returnCode = Command::FAILURE;
                }
            }
        }

        return $returnCode;
    }

    /**
     * Deletes per-day puzzle folders under docs/{locale}/{Ymd}/ older than three months.
     */
    private function removePuzzlesOlderThanThreeMonths(SymfonyStyle $io, Filesystem $filesystem): void
    {
        $cutoff = (new \DateTimeImmutable('today'))->modify('-3 months');

        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            $langDir = $this->docsDirectoryDestination.'/'.$lang;
            if (!$filesystem->exists($langDir)) {
                continue;
            }

            $entries = scandir($langDir);
            if (false === $entries) {
                continue;
            }

            $removed = 0;
            foreach ($entries as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }

                if (!preg_match('/^\d{8}$/', $name)) {
                    continue;
                }

                $path = $langDir.'/'.$name;
                if (!is_dir($path)) {
                    continue;
                }

                $puzzleDay = \DateTimeImmutable::createFromFormat('!Ymd', $name);
                if (false === $puzzleDay || $puzzleDay >= $cutoff) {
                    continue;
                }

                $filesystem->remove($path);
                ++$removed;
            }

            if ($removed > 0) {
                $io->info(sprintf('Removed %d puzzle folder(s) older than 3 months for %s.', $removed, $lang));
            }
        }
    }
}
