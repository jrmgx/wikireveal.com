<?php

namespace App\Text;

use App\Language\LanguageInterface;
use Psr\Container\ContainerInterface;

class SolutionCheckerBuilder
{
    public function __construct(
        private readonly ContainerInterface $languageProvider,
        private readonly VariationComputer $variationComputer,
        private readonly Hasher $hasher,
        private readonly Encrypter $encrypter,
    ) {
    }

    public function buildSolutionChecker(string $language, array $tokens, array $winTokens)
    {
        $variationsMap = $this->variationComputer->computeVariationsMap($language, $tokens);

        $variationsMapSecured = [];

        foreach ($variationsMap as $normalized => $variations) {
            $normalizedHash = $this->hasher->hash($normalized);
            $variationsSecured = $this->encrypter->encrypt(json_encode($variations), $normalized);
            $variationsMapSecured[$normalizedHash] = $variationsSecured;
        }

        if (!$this->languageProvider->has($language)) {
            throw new \InvalidArgumentException("Language $language is not supported.");
        }
        /** @var LanguageInterface */
        $language = $this->languageProvider->get($language);

        // Remove one-letter and non-alphabetical tokens: https://regex101.com/r/jVVLjx/1
        $winTokens = array_filter($winTokens, fn (string $e) => !preg_match('/^(.|[^a-z]+?)$/misu', $e));
        $winTokens = array_filter($winTokens, fn (string $e) => false === $language->isPonctuation($e));
        $winTokens = array_map($language->normalize(...), $winTokens);

        $winTokenHashes = array_map($this->hasher->hash(...), $winTokens);
        $winTokenHashes = array_values($winTokenHashes);

        $winHash = $this->hasher->hash(implode('', $winTokens));
        $winPassword = implode('', $winTokens);

        $variationsMapSecured[$winHash] = $this->encrypter->encrypt(
            json_encode(array_values(array_unique($tokens))),
            $winPassword,
        );

        return [
            'variationsMap' => $variationsMapSecured,
            'winHashes' => $winTokenHashes,
        ];
    }
}
