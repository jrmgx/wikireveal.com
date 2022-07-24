<?php

namespace App\Controller;

use App\Language\LanguageInterface;
use DateTimeImmutable;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Wikimedia\Parsoid\Config\Api\ApiHelper;
use Wikimedia\Parsoid\Config\Api\DataAccess;
use Wikimedia\Parsoid\Config\Api\PageConfig;
use Wikimedia\Parsoid\Config\Api\SiteConfig;
use Wikimedia\Parsoid\Core\PageBundle;
use Wikimedia\Parsoid\Parsoid;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly ServiceProviderInterface $languageProvider,
    ) {
    }

    #[Route('/')]
    public function index(): Response
    {
        $langages = [];
        foreach ($this->languageProvider->getProvidedServices() as $lang => $class) {
            /** @var LanguageInterface $langage */
            $langage = $this->languageProvider->get($lang);
            $langages[] = $langage->lang();
        }

        return $this->render('index.html.twig', [
            'languages' => $langages,
        ]);
    }

    #[Route(path: '/{_locale}')]
    public function wikinize(Request $request): Response
    {
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
            // TODO link to github explain how to build one
        }

        $getPage = $this->cache('getPage-'.$puzzleId.'.json', fn () => $this->getPage($article, $lang));
        if (!isset($getPage['query']['pages'][0]['revisions'][0]['slots']['main']['content'])) {
            throw new RuntimeException('Malformed JSON Content.');
        }

        // To win the player has to find all the meaningful words of the subject,
        $subject = $this->decodeSubject($article);
        // so we are filtering the others.
        $winTokens = $this->tokenize($subject);
        // Remove one-letter and non-alphabetical tokens: https://regex101.com/r/jVVLjx/1
        $winTokens = array_filter($winTokens, fn (string $e) => !preg_match('/^(.|[^a-z]+?)$/misu', $e));
        $winTokens = array_filter($winTokens, fn (string $e) => false === $language->isPonctuation($e));
        $winTokens = array_filter($winTokens, fn (string $e) => !$this->hasQuote($e));
        $winTokens = array_map($language->normalize(...), $winTokens);
        $winTokens = array_map($this->getHash(...), $winTokens);
        $winTokens = array_values($winTokens);

        $wikiData = $getPage['query']['pages'][0]['revisions'][0]['slots']['main']['content'];
        $wikiHtml = $this->cache('wikiToHtml-'.$puzzleId.'.html', fn () => $this->wikiToHtml($lang, $subject, $wikiData));

        $cleanHtml = $this->removeUnwantedBlocks($wikiHtml, $language);

        $pureHtml = $this->cache('cleanupMarkup-'.$puzzleId.'.html', fn () => $this->cleanupMarkup($cleanHtml));
        $pureHtml = "<section><h1>$subject</h1></section>".$pureHtml;
        $pureHtml = str_replace(['<body>', '</body>'], '', $pureHtml);

        $tokens = $this->tokenize($pureHtml);

        $outputs = [];
        foreach ($tokens as $token) {
            $normalized = $language->normalize($token);
            if ($this->isHtmlMarkup($token)) {
                $outputs[] = $token;
            } elseif ($this->hasQuote($token)) {
                $outputs[] = '<span class="wz-w-ponctuation-quote">'.$token.'</span>';
            } elseif (($ponctuationSize = $language->isPonctuation($token)) !== false) {
                $outputs[] = '<span class="wz-w-ponctuation-'.$ponctuationSize.'">'.$token.'</span>';
            } else {
                $hash = $this->getHash($normalized);
                $size = $this->getSize($token);
                $encoded = $this->getEncoded($token);
                $placeholder = $this->getPlaceholder($size);
                $outputs[] = '<span data-hash="'.$hash.'" class="wz-w-hide" data-size="'.$size.'" data-word="'.$encoded.'">'.$placeholder.'</span>';
                // For debug
                // $outputs[] = '<span data-hash="'.$hash.'" class="wz-w-hide" data-size="'.$size.'" data-word="'.$encoded.'">'.$token.'</span>';
            }
        }

        return $this->render('wikinize.html.twig', [
            'lang' => $lang,
            'outputs' => $outputs,
            'puzzle_id' => $puzzleId,
            'common_words' => $language->commonWords(),
            'win_hashes' => $winTokens,
            'ui_messages' => [
                'victory' => $this->translator->trans('message.victory'),
                'already_sent' => $this->translator->trans('error.already_sent'),
            ],
        ]);
    }

    private function isHtmlMarkup(string $candidate): bool
    {
        return str_starts_with($candidate, '<');
    }

    private function hasQuote(string $candidate): bool
    {
        return str_contains($candidate, "'");
    }

    private function getPlaceholder(int $size): string
    {
        return str_repeat('&nbsp;', $size);
    }

    private function getSize(string $word): int
    {
        return mb_strlen($word);
    }

    private function getHash(string $word): string
    {
        return sha1($word);
    }

    private function getEncoded(string $word): string
    {
        return base64_encode(urlencode($word));
    }

    /**
     * pages.0.revisions.0.slot.main.content
     * https://www.mediawiki.org/wiki/API:Get_the_contents_of_a_page.
     *
     * @return array<mixed>
     */
    private function getPage(string $subject, string $lang): array
    {
        $response = $this->httpClient->request(
            'GET',
            "https://$lang.wikipedia.org/w/api.php".
            '?action=query'.
            '&format=json'.
            '&prop=revisions'.
            "&titles=$subject".
            '&formatversion=2'.
            '&rvprop=content'.
            '&rvslots=*'
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

    // https://github.com/wikimedia/parsoid/blob/master/tests/phpunit/Parsoid/ParsoidTest.php
    private function wikiToHtml(string $lang, string $subject, string $wikiData): string
    {
        $wikiApiHelper = new ApiHelper([
            'apiEndpoint' => "https://$lang.wikipedia.org/w/api.php",
        ]);
        $wikiSiteConfig = new SiteConfig($wikiApiHelper, [
            'logger' => $this->logger,
        ]);
        $wikiDataAccess = new DataAccess($wikiApiHelper, $wikiSiteConfig, []);
        $wikiPageConfig = new PageConfig($wikiApiHelper, [
            'title' => $subject,
            'pageLanguage' => $lang,
            'pageLanguageDir' => 'ltr',
            'pageContent' => $wikiData,
        ]);
        $wikiParsoid = new Parsoid($wikiSiteConfig, $wikiDataAccess);

        /** @var PageBundle $pageBundle */
        $pageBundle = $wikiParsoid->wikitext2html($wikiPageConfig, [
            'wrapSections' => true,
            'pageBundle' => true,
            'body_only' => true,
            'outputContentVersion' => null,
            'contentmodel' => null,
            'discardDataParsoid' => true,
            'logLinterData' => true,
        ]);

        return $pageBundle->toHtml();
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

    // http://htmlpurifier.org/live/configdoc/plain.html#HTML.Allowed
    // http://htmlpurifier.org/docs/enduser-customize.html
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
        $prepareHtml = str_replace("'", '__QUOTE__ ', $prepareHtml);
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
}
