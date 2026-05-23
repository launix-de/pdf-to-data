<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Integration;

use Launix\PdfToData\PdfReader;
use PHPUnit\Framework\TestCase;

final class QuotationExtractionTest extends TestCase
{
    public function testItExtractsTheExpectedQuotationRowsFromAngebot1000(): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/public/angebot-1000.pdf';
        self::assertFileExists($fixture);

        $reader = PdfReader::fromFile($fixture);
        $reader->removeFooters();
        $quotation = $reader->extractSalesDocument()['quotation'] ?? null;

        self::assertIsArray($quotation);
        self::assertSame(
            ['beschreibung', 'einzelpreis', 'menge', 'einheit', 'gesamt'],
            $quotation['schema'] ?? null
        );

        self::assertSame(
            [
                [
                    'position' => '1.',
                    'beschreibung' => "Montageleistung\nMontageleistung nach\nStundenabrechnung",
                    'einzelpreis' => 55,
                    'menge' => 100,
                    'einheit' => 'h',
                    'gesamt' => 5500,
                ],
                [
                    'position' => null,
                    'beschreibung' => 'Schraube',
                    'einzelpreis' => 0.25,
                    'menge' => 10000,
                    'einheit' => 'Stück',
                    'gesamt' => 2500,
                ],
            ],
            $quotation['line_items'] ?? null
        );

        self::assertSame(8000, $quotation['subtotal'] ?? null);
        self::assertSame(
            [
                'net' => 8000,
                'vat_label' => 'MwSt. (19%)',
                'vat' => 1520,
                'gross' => 9520,
            ],
            $quotation['totals'] ?? null
        );
    }
}
