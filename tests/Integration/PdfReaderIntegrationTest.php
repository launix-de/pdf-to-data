<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Integration;

use Launix\PdfToData\PdfReader;
use PHPUnit\Framework\TestCase;
use Launix\PdfToData\Tests\Support\SyntheticPdfFactory;

final class PdfReaderIntegrationTest extends TestCase
{
    public function testItExtractsTextFromSyntheticPdf(): void
    {
        $reader = PdfReader::fromString(
            SyntheticPdfFactory::multiPageDocument([
                ['body' => ['Hello PDF']],
            ]),
            'synthetic.pdf'
        );
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
}
