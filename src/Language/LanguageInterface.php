<?php

namespace App\Language;

interface LanguageInterface
{
    public static function lang(): string;

    public function langName(): string;

    /**
     * Given a word, return a version without accents/diacritics, and in lowercase.
     */
    public function normalize(string $word): string;

    /**
     * Given a normalized word, return different possible versions of it.
     * The goal is: when the player enter a word, it would reveal similar ones.
     * It can be for example plural, singular, feminine or conjugated versions.
     * You choose how far you want to go and then explicit it for the player in the ui.note.
     *
     * @return array<string>
     */
    public function variations(string $normalized): array;

    /**
     * Return the list of most common word for that language.
     * Those words will be given at start.
     * They must be normalized.
     *
     * @return array<int, string>
     */
    public function commonWords(): array;

    /**
     * Return the size needed for this ponctuation sign, false for not a ponctuation sign.
     * ie: in French:
     *  - ":" needs a space before and after so it's 2
     *  - "," only needs a space after, so it's 1
     *  - "-" needs no spacing at all, so it's 0
     *  - "«" needs a space before, so it's -1.
     */
    public function isPonctuation(string $normalized): int|false;

    /**
     * Return a CSS selector list of HTML block from the wiki page that we want to remove.
     * ie: homonym, section is not complete, etc.
     *
     * @return array<int, string>
     */
    public function uselessBlockSelectors(): array;

    /**
     * List of selector from where we stop the page rendering.
     * ie: when block annexes, bibliography, references, etc. is found stop.
     *
     * @return array<int, string>
     */
    public function endSelectors(): array;

    /**
     * Return a list of wikipedia page url to pick for.
     *
     * ie: for french the list can be built by navigating to https://fr.wikipedia.org/wiki/Wikip%C3%A9dia:Articles_vitaux/Niveau_4
     * then in each Section taking articles that are grade B or more.
     *
     * This piece of javascript will help to get a raw list (execute this on a javascript console on a wikipedia page):
     * ```
     * var links = [];
     * // Adapt for other languages
     * var list = document.querySelectorAll(
     *     '[title="Featured article"],[title="Good article"],[title="A-Class article"],[title="B-Class article"]'
     * );
     * for (let e of list) {
     *     let as = e.parentNode.querySelectorAll('a');
     *     if (as.length === 0) continue;
     *     let a = as[as.length - 1];
     *     if (!a.href.startsWith('')) continue;
     *     links.push(a.href);
     * }
     * JSON.stringify(links);
     * ```
     * The final list order has to be pseudo random but stable, and if possible non easily guessable by a human eye.
     * The list must only contain the final part of the url: strip the 'https://lang.wikipedia.org/wiki/' part.
     *
     * @return array<int, string>
     */
    public function articles(): array;
}
