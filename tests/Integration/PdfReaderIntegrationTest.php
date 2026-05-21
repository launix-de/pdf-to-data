<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Integration;

use Launix\PdfToData\PdfReader;
use PHPUnit\Framework\TestCase;

final class PdfReaderIntegrationTest extends TestCase
{
    public function testItExtractsTextFromSyntheticPdf(): void
    {
        $reader = PdfReader::fromString($this->buildSimplePdf('Hello PDF'), 'synthetic.pdf');
        $document = $reader->removeFooters();
        $elements = $document->elements();

        self::assertNotEmpty($document->pages());
        self::assertIsArray($elements);

        $text = '';
        foreach ($elements as $element) {
            if (($element['type'] ?? '') === 'text') {
                $text .= (string)($element['text'] ?? '');
            }
        }

        self::assertStringContainsString('HelloPDF', preg_replace('/\s+/u', '', $text) ?? '');
    }

    private function buildSimplePdf(string $text): string
    {
        $objects = [];
        $objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
        $objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj';
        $objects[] = '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';
        $stream = "BT /F1 24 Tf 72 720 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ") Tj ET";
        $objects[] = '5 0 obj << /Length ' . strlen($stream) . " >> stream\n" . $stream . "\nendstream endobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}
