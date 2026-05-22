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

        self::assertNotEmpty($document->pages());
        self::assertGreaterThan(0, count($document->elements()));
        self::assertGreaterThan(0, (float)($document->meta()['stream_height'] ?? 0));
    }

    public function testSalesDocumentExtractionUsesNormalizedElements(): void
    {
        $pdf = SyntheticPdfFactory::multiPageDocument([
            [
                'header' => 'REPEATED HEADER',
                'body' => ['Offer 1001', 'Window White PVC'],
                'footer' => 'REPEATED FOOTER',
            ],
            [
                'header' => 'REPEATED HEADER',
                'body' => ['Offer 1002', 'Door Anthracite'],
                'footer' => 'REPEATED FOOTER',
            ],
        ]);

        $reader = PdfReader::fromString($pdf, 'sales.pdf');
        $normalized = $reader->removeFooters();
        $salesDocument = $reader->extractSalesDocument();
        $normalizedText = $this->extractText($normalized->elements());
        $text = $this->normalize(implode(' ', array_map('strval', $salesDocument['text'] ?? [])));

        self::assertSame($normalizedText, $text);
        self::assertStringContainsString('window white pvc', $text);
        self::assertStringContainsString('door anthracite', $text);
        self::assertSame(1, substr_count($text, 'repeated header'));
        self::assertSame(1, substr_count($text, 'repeated footer'));
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
