<?php

namespace App\Text;

use App\Language\LanguageInterface;
use Psr\Container\ContainerInterface;

class VariationComputer
{
    public function __construct(
        private readonly ContainerInterface $languageProvider,
    ) {
    }

    public function computeVariationsMap(string $language, array $tokens): array
    {
        if (!$this->languageProvider->has($language)) {
            throw new \InvalidArgumentException("Language $language is not supported.");
        }
        /** @var LanguageInterface */
        $language = $this->languageProvider->get($language);

        $map = [];

        $tokens = array_flip($tokens);

        foreach ($tokens as $token => $_) {
            $normalized = $language->normalize($token);

            $map[$normalized] ??= [];
            $map[$normalized] += $this->getAllVariations($language, $token);
        }

        foreach ($map as $normalized => $variations) {
            foreach ($variations as $variation => $_) {
                $normalized2 = $language->normalize($variation);
                $map[$normalized2] ??= [];
                $map[$normalized2] += $variations;
            }
        }

        $finalMap = [];

        foreach ($map as $normalized => $variations) {
            $finalMap[$normalized] = array_keys(array_intersect_key($variations, $tokens));
        }

        return $finalMap;
    }

    private function getAllVariations(LanguageInterface $language, string $token): array
    {
        $variations = [];

        foreach ($language->variations($token) as $variation) {
            $variations[$variation] = true;
        }

        return $variations;
    }
}
