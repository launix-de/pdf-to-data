<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Integration;

use Launix\PdfToData\PdfReader;
use Launix\PdfToData\Tests\Support\SyntheticPdfFactory;
use PHPUnit\Framework\TestCase;

final class XtractNormalizationTest extends TestCase
{
    public function testItRemovesRepeatedHeadersAndFootersFromMultiPageSyntheticPdf(): void
    {
        $pdf = SyntheticPdfFactory::multiPageDocument([
            [
                'header' => 'FIRST PAGE HEADER',
                'body' => ['Alpha Body Line', 'Shared Body Line'],
                'footer' => 'REPEATED FOOTER',
            ],
            [
                'header' => 'REPEATED HEADER',
                'body' => ['Beta Body Line', 'Shared Body Line'],
                'footer' => 'REPEATED FOOTER',
            ],
            [
                'header' => 'REPEATED HEADER',
                'body' => ['Gamma Body Line', 'Shared Body Line'],
                'footer' => 'REPEATED FOOTER',
            ],
        ]);

        $reader = PdfReader::fromString($pdf, 'multipage.pdf');
        $document = $reader->removeFooters();
        $text = $this->extractText($document->elements());

        self::assertStringContainsString('alpha body line', $text);
        self::assertStringContainsString('beta body line', $text);
        self::assertStringContainsString('gamma body line', $text);
        self::assertStringContainsString('shared body line', $text);

        self::assertStringContainsString('first page header', $text);
        self::assertStringNotContainsString('repeated header', $text);
        self::assertSame(1, substr_count($text, 'repeated footer'));

        self::assertCount(1, $document->pages());
        self::assertSame(0, $document->pages()[0]['page_index'] ?? null);
        self::assertGreaterThan(0, count($document->elements()));
        self::assertGreaterThan(0, (float)($document->meta()['stream_height'] ?? 0));
        self::assertSame([0], array_values(array_unique(array_map(
            static fn(array $element): int => (int)($element['page_index'] ?? -1),
            $document->elements()
        ))));
    }

    public function testItPreservesOfferPositionAnchorsInTheConsolidatedStream(): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/public/006952_01_2026_OF.pdf';
        self::assertFileExists($fixture);

        $reader = PdfReader::fromFile($fixture);
        $document = $reader->removeFooters();
        $positions = [];
        $repeatedFeatureLines = [
            'RC2' => 0,
            'SCHEIBE UMLAUFEND EINGEKLEBT' => 0,
            'SCHWARZE DICHTUNG' => 0,
            'WARME KANTE SCHWARZ' => 0,
            'TRANSPORTLEISTE' => 0,
            'TRANSPORTGURTE' => 0,
            'DÜBELBOHRUNG LINKS+RECHTS' => 0,
        ];
        foreach ($document->elements() as $element) {
            if (($element['type'] ?? '') !== 'text') {
                continue;
            }
            $text = (string)($element['text'] ?? '');
            if (preg_match('/\bPos\.\s*(\d+)\./u', (string)($element['text'] ?? ''), $matches) === 1) {
                $positions[(int)$matches[1]] = true;
            }
            if (array_key_exists($text, $repeatedFeatureLines)) {
                $repeatedFeatureLines[$text]++;
            }
        }

        ksort($positions, SORT_NUMERIC);
        self::assertSame(range(1, 68), array_keys($positions));
        foreach ($repeatedFeatureLines as $label => $count) {
            self::assertLessThanOrEqual(2, $count, sprintf('Repeated feature line still duplicated in stream: %s', $label));
        }
    }

    /**
     * @param array<int,array<string,mixed>> $elements
     */
    private function extractText(array $elements): string
    {
        $parts = [];
        foreach ($elements as $element) {
            if (($element['type'] ?? '') === 'text') {
                $parts[] = (string)($element['text'] ?? '');
            }
        }

        return $this->normalize(implode(' ', $parts));
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
