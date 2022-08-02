<?php

namespace App\Controller;

use App\Language\LanguageInterface;
use DateTimeImmutable;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TranslatorInterface $translator,
        private readonly ServiceProviderInterface $languageProvider,
        private readonly RouterInterface $router,
        private readonly string $docsDirectory,
    ) {
    }

    #[Route('/')]
    public function index(string $assetVersion = 'assets'): Response
    {
        $langages = [];
        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            /** @var LanguageInterface $langage */
            $langage = $this->languageProvider->get($lang);
            $langages[$langage->lang()] = $langage->langName();
        }

        return $this->render('index.html.twig', [
            'asset_version_major' => $assetVersion,
            'languages' => $langages,
        ]);
    }

    #[Route('/{_locale}/archive')]
    public function archive(Request $request, string $assetVersion = 'assets'): Response
    {
        $archives = [];
        $lang = $request->getLocale();
        $date = (new DateTimeImmutable())->format('Ymd');

        $gamesDirectories = (new Finder())->in($this->docsDirectory.'/'.$lang)->directories();
        foreach ($gamesDirectories as $gameDirectory) {
            $gameDate = $gameDirectory->getFilename();
            $dateTime = \DateTime::createFromFormat('Ymd', $gameDate);
            if (false === $dateTime) {
                continue;
            }

            if ($gameDate === $date) {
                continue; // Today's puzzle
            }

            $indexFile = $gameDirectory->getRealPath().'/index.html';
            if (!file_exists($indexFile)) {
                continue;
            }

            $head = $this->headOfFile($indexFile, 30);
            $subject = $this->findSubject($head) ?? $this->translator->trans('archives.unknown');
            $archives[] = [
                'url' => $this->router->generate('app_index_wikireveal', ['_locale' => $lang]).'/'.$gameDate,
                'subject' => $subject,
                'date' => $dateTime->format('Y-m-d'),
            ];
        }

        return $this->render('archive.html.twig', [
            'asset_version_major' => $assetVersion,
            'archives' => $archives,
        ]);
    }

    #[Route(path: '/{_locale}')]
    public function wikireveal(Request $request, string $assetVersion = 'assets'): Response
    {
        $debug = false;
        $lang = $request->getLocale();
        $dateInt = (int) (new DateTimeImmutable())->format('Ymd');
        $puzzleId = $lang.'-'.$dateInt;

        try {
            /** @var LanguageInterface $language */
            $language = $this->languageProvider->get($lang);
            $articles = $language->articles();
            $article = $articles[$dateInt % \count($articles)];
        } catch (Exception) {
            throw $this->createNotFoundException('This language is not available yet.');
            // TODO link to github explain how to add one
        }

        $pageHtml = $this->cache('pageHtml-'.$puzzleId.'.json', fn () => $this->getPageHtml($article, $lang));
        if (!isset($pageHtml['parse']['text'])) {
            throw new RuntimeException('Malformed JSON Content.');
        }

        // To win the player has to find all the meaningful words of the subject,
        // so we are filtering the others.
        $subject = $this->decodeSubject($article);
        $encodedSubject = $this->getEncoded($subject);
        $winTokens = $this->tokenize($subject);
        // Remove one-letter and non-alphabetical tokens: https://regex101.com/r/jVVLjx/1
        $winTokens = array_filter($winTokens, fn (string $e) => !preg_match('/^(.|[^a-z]+?)$/misu', $e));
        $winTokens = array_filter($winTokens, fn (string $e) => false === $language->isPonctuation($e));
        $winTokens = array_map($language->normalize(...), $winTokens);
        $winTokens = array_map($this->getHash(...), $winTokens);
        $winTokens = array_values($winTokens);

        $wikiHtml = $pageHtml['parse']['text'];

        $cleanHtml = $this->removeUnwantedBlocks($wikiHtml, $language);

        $pureHtml = $this->cache('cleanupMarkup-'.$puzzleId.'.html', fn () => $this->cleanupMarkup($cleanHtml));
        $pureHtml = "<section><h1>$subject</h1></section>".$pureHtml;
        $pureHtml = str_replace(['<body>', '</body>'], '', $pureHtml);

        $tokens = $this->tokenize($pureHtml);

        $outputs = [];
        foreach ($tokens as $token) {
            if ($this->isHtmlMarkup($token)) {
                $outputs[] = $token;
            } elseif (($ponctuationSize = $language->isPonctuation($token)) !== false) {
                $outputs[] = '<span class="wz-w-ponctuation-'.$ponctuationSize.'">'.$token.'</span>';
            } else {
                $normalized = $language->normalize($token);
                $size = $this->getSize($token);
                $encoded = $this->getEncoded($token);
                $placeholder = $this->getPlaceholder($size);
                /* @phpstan-ignore-next-line */
                if ($debug) {
                    $hashes = implode('|', $language->variations($normalized));
                    $outputs[] = '<span data-hash="'.$hashes.'" class="wz-w-hide" data-size="'.$size.'" data-word="'.$encoded.'">'.$token.'</span>';
                } else {
                    $hashes = implode('|', array_map($this->getHash(...), $language->variations($normalized)));
                    $outputs[] = '<span data-hash="'.$hashes.'" class="wz-w-hide" data-size="'.$size.'" data-word="'.$encoded.'">'.$placeholder.'</span>';
                }
            }
        }

        return $this->render('wikireveal.html.twig', [
            'asset_version_major' => $assetVersion,
            'lang' => $lang,
            'outputs' => $outputs,
            'puzzle_id' => $puzzleId,
            'encoded_subject' => $encodedSubject,
            'common_words' => $language->commonWords(),
            'win_hashes' => $winTokens,
            'ui_messages' => [
                'victory' => $this->translator->trans('message.victory'),
                'already_sent' => $this->translator->trans('error.already_sent'),
                'share' => $this->translator->trans('ui.action.share'),
                'share_public' => $this->translator->trans('message.share_public', ['count' => 999]),
                'share_error' => $this->translator->trans('error.share_error'),
            ],
        ]);
    }

    private function isHtmlMarkup(string $candidate): bool
    {
        return str_starts_with($candidate, '<');
    }

    private function getPlaceholder(int $size): string
    {
        return str_repeat('&nbsp;', $size);
    }

    private function getSize(string $word): int
    {
        return mb_strlen($word);
    }

    private function getHash(string $normalized): string
    {
        return mb_substr(sha1($normalized), 0, 10);
    }

    private function getEncoded(string $word): string
    {
        return base64_encode(urlencode($word));
    }

    /**
     * .parse.text
     * https://www.mediawiki.org/wiki/API:Get_the_contents_of_a_page#Method_2:_Use_the_Parse_API.
     *
     * @return array<mixed>
     */
    private function getPageHtml(string $subject, string $lang): array
    {
        // https://en.wikipedia.org/w/api.php?action=parse&format=json&page=Pet_door&prop=text&formatversion=2
        $response = $this->httpClient->request(
            'GET',
            "https://$lang.wikipedia.org/w/api.php".
            "?action=parse&format=json&page=$subject&prop=text&formatversion=2"
        );
        $content = $response->getContent();

        return json_decode($content, true);
    }

    /**
     * We use the Symfony DOMCrawler to remove HTML element we don't want.
     * It's a bit hacky because this component is not made for that but it works.
     * https://symfony.com/doc/current/components/dom_crawler.html.
     */
    private function removeUnwantedBlocks(string $html, LanguageInterface $language): string
    {
        $crawler = new Crawler($html);
        $reachedEnd = false;
        // This was not working for unknown reason
        // $crawler = $crawler->filter('*')->reduce(function (Crawler $node) {
        //     return !$node->matches('.bandeau-container'); // ...
        // });
        // So we use a trick seen at https://github.com/symfony/symfony/issues/14152#issuecomment-88627681
        $crawler->filter('*')->each(function (Crawler $crawler) use (&$reachedEnd, $language) {
            if ($reachedEnd) {
                $this->removeAllNodes($crawler);

                return;
            }

            // Localized end selectors
            foreach ($language->endSelectors() as $selector) {
                if ($crawler->matches($selector)) {
                    $reachedEnd = true;
                    $this->removeAllNodes($crawler);

                    return;
                }
            }

            // Common HTML selector
            if (
                $crawler->matches('table') ||
                $crawler->matches('link') ||
                $crawler->matches('.navbar') ||
                $crawler->matches('.infobox_v3') ||
                $crawler->matches('.infobox_v2') ||
                $crawler->matches('.reference') ||
                $crawler->matches('.noprint') ||
                $crawler->matches('.toc') ||
                $crawler->matches('.mw-editsection') ||
                $crawler->matches('.thumb.tright') ||
                $crawler->matches('a[href$="\.ogg"]') ||
                $crawler->matches('.mw-reflink-text') ||
                $crawler->matches('figcaption') ||
                $crawler->matches('img') ||
                $crawler->matches('div.legend') // We remove images so remove legends too
            ) {
                $this->removeAllNodes($crawler);

                return;
            }

            // Localized selectors
            foreach ($language->uselessBlockSelectors() as $selector) {
                if ($crawler->matches($selector)) {
                    $this->removeAllNodes($crawler);

                    return;
                }
            }
        });

        return $crawler->html();
    }

    /**
     * The Crawler instance wraps DOM elements by reference so our changes here will be reflected.
     */
    private function removeAllNodes(Crawler $crawler): void
    {
        foreach ($crawler as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * http://htmlpurifier.org/live/configdoc/plain.html#HTML.Allowed
     * http://htmlpurifier.org/docs/enduser-customize.html.
     */
    private function cleanupMarkup(string $html): string
    {
        $HTMLPurifierConfig = HTMLPurifier_Config::createDefault();
        // $HTMLPurifierConfig->set('URI.DisableExternalResources', true);
        // $HTMLPurifierConfig->set('URI.DisableResources', true);
        $HTMLPurifierConfig->set('HTML.Allowed', 'h1,h2,h3,h4,h5,section,p,i');
        $HTMLPurifierConfig->set('HTML.AllowedAttributes', '*.id,*.class,*.src');
        $HTMLDefinition = $HTMLPurifierConfig->getHTMLDefinition(true);
        $HTMLDefinition->addElement(
            'section',   // name
            'Block',  // content set
            'Flow', // allowed children
            'Common', // attribute collection
            [] // attributes
        );
        $HTMLPurifier = new HTMLPurifier($HTMLPurifierConfig);

        return $HTMLPurifier->purify($html);
    }

    private function decodeSubject(string $subject): string
    {
        return str_replace('_', ' ', urldecode($subject));
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $html): array
    {
        $prepareHtml = str_replace("\n", ' ', $html);
        // Trick to keep quotes around (otherwise they are considered as word boundaries and got removed later)
        // https://regex101.com/r/9d0ofu/3
        $prepareHtml = preg_replace('/(\b\w)[\'’]|[\'’](\w\b)/miu', ' $1__QUOTE__$2 ', $prepareHtml);
        // https://regex101.com/r/fedzhl/1
        $prepareHtml = preg_replace('/(<.*?>|\b|\(|\)|\.|;|-|,)/misu', "\n$1\n", $prepareHtml);
        $prepareHtml = str_replace('__QUOTE__', "'", $prepareHtml);

        $tokens = explode("\n", $prepareHtml);
        $tokens = array_filter($tokens, fn (string $e) => '' !== preg_replace('/\s/miu', '', $e));

        return array_map(trim(...), $tokens);
    }

    /**
     * Pseudo cache.
     * Act as a quite basic caching mechanism
     * and help developers to check intermediate transformation states.
     */
    private function cache(string $key, callable $compute): mixed
    {
        $file = __DIR__.'/../../var/cache/'.$key;
        $ext = pathinfo($file, \PATHINFO_EXTENSION);
        if (file_exists($file)) {
            $data = file_get_contents($file);
            if (false === $data) {
                throw new RuntimeException('Error while getting cache from '.$file);
            }

            if ('json' === $ext) {
                return json_decode($data, true);
            }

            return $data;
        }

        $data = $compute();
        if ('json' === $ext) {
            $encoded = json_encode($data);
            file_put_contents($file, $encoded);
        } else {
            file_put_contents($file, $data);
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private function headOfFile(string $file, int $lines): array
    {
        $handle = fopen($file, 'r+');
        if (false === $handle) {
            return [];
        }

        $contents = [];
        $i = 0;
        while (!feof($handle) && $i < $lines) {
            $contents[] = (string) fread($handle, 8192);
        }
        fclose($handle);

        return $contents;
    }

    /**
     * @param array<string> $lines
     */
    private function findSubject(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/<meta name="subject" content="(.*?)">/misu', $line, $matches)) {
                return trim(urldecode((string) base64_decode($matches[1], true)));
            }
        }

        return null;
    }
}
