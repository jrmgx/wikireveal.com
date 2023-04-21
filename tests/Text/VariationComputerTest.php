<?php

namespace App\Tests\Text;

use App\Language\FrenchLanguage;
use App\Text\VariationComputer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class VariationComputerTest extends TestCase
{
    public function provideComputeVariationMap(): iterable
    {
        yield [
            ['le' => $v = ['le'], 'les' => $v],
            ['le'],
        ];
        yield [
            ['le' => $v = ['les'], 'les' => $v],
            ['les'],
        ];
        yield [
            ['le' => $v = ['Les'], 'les' => $v],
            ['Les'],
        ];
        yield [
            ['le' => $v = ['le', 'les'], 'les' => $v],
            ['le', 'les'],
        ];
        yield [
            ['le' => $v = ['le', 'Les'], 'les' => $v],
            ['le', 'Les'],
        ];
        yield [
            ['le' => $v = ['le', 'les', 'Les'], 'les' => $v],
            ['le', 'les', 'Les'],
        ];
        yield [
            ['le' => $v = ['le', 'les', 'Les', 'Le'], 'les' => $v],
            ['le', 'les', 'Les', 'Le'],
        ];
        yield [
            ['le' => $v = ['Le', 'LES'], 'les' => $v],
            ['Le', 'LES'],
        ];
        yield [
            ['le' => $v = ['Le', 'LES'], 'les' => $v, 'chat' => $v2 = ['Chats'], 'chats' => $v2],
            ['Le', 'LES', 'Chats'],
        ];
        yield [
            ['le' => $v = ['Le', 'LE'], 'les' => $v, 'chat' => $v2 = ['Chats'], 'chats' => $v2],
            ['Le', 'LE', 'Chats'],
        ];
        yield [
            // Missing accent on purpose on the keys
            ['etablissement' => $v = ['établissement'], 'etablissements' => $v],
            ['établissement'],
        ];
        yield [
            // Missing accent on purpose on the keys
            ['etablissement' => $v = ['Établissements', 'ÉTABLISSEMENTS'], 'etablissements' => $v],
            ['Établissements', 'ÉTABLISSEMENTS'],
        ];
    }

    /**
     * @dataProvider provideComputeVariationMap
     */
    public function test($expected, $tokens)
    {
        $variationComputer = new VariationComputer(new ServiceLocator([
            'fr' => fn () => new FrenchLanguage(),
        ]));
        $map = $variationComputer->computeVariationMap('fr', $tokens);

        $this->sortMap($map);
        $this->sortMap($expected);

        $this->assertSame($expected, $map);
    }

    // For test stability
    private function sortMap(array &$map): void
    {
        ksort($map);
        foreach ($map as &$variations) {
            sort($variations);
        }
    }
}
