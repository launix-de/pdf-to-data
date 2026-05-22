<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Integration;

use Launix\PdfToData\NormalizedDocument;
use Launix\PdfToData\PdfReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CorpusExtractionTest extends TestCase
{
    /**
     * @return array<string,array{0:?array}>
     */
    public static function corpusProvider(): array
    {
        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $results = [];

        if (is_dir($fixturesRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fixturesRoot, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
                    continue;
                }

                $pdfPath = $file->getPathname();
                $expectedPath = preg_replace('/\.pdf$/i', '.expected.json', $pdfPath);
                $results[basename($pdfPath)] = [[
                    'pdf' => $pdfPath,
                    'expected' => $expectedPath,
                ]];
            }
        }

        if ($results === []) {
            return ['no-fixtures' => [null]];
        }

        ksort($results, SORT_NATURAL | SORT_FLAG_CASE);
        return $results;
    }

    #[DataProvider('corpusProvider')]
    public function testPdfCorpusFixtures(?array $fixture): void
    {
        if ($fixture === null) {
            $this->markTestSkipped('No PDF fixtures found in tests/Fixtures.');
        }

        $pdfPath = (string)$fixture['pdf'];
        $expectedPath = (string)$fixture['expected'];

        self::assertFileExists($pdfPath);
        self::assertFileExists(
            $expectedPath,
            sprintf('Missing expected values for fixture %s', basename($pdfPath))
        );

        $expected = json_decode((string)file_get_contents($expectedPath), true);
        self::assertIsArray($expected, sprintf('Invalid expected JSON for %s', basename($pdfPath)));

        $reader = PdfReader::fromFile($pdfPath);
        $normalized = $reader->removeFooters();
        $salesDocument = $reader->extractSalesDocument();

        $this->assertNormalizedDocument($normalized, is_array($expected['normalized'] ?? null) ? $expected['normalized'] : []);
        $this->assertSalesDocument($salesDocument, is_array($expected['sales_document'] ?? null) ? $expected['sales_document'] : []);
    }

    /**
     * @param array<string,mixed> $expected
     */
    private function assertNormalizedDocument(NormalizedDocument $document, array $expected): void
    {
        $meta = $document->meta();
        $pages = $document->pages();
        $elements = $document->elements();
        $html = $document->html();
        $text = $this->normalizeText(implode(' ', array_map(
            static fn(array $element): string => ($element['type'] ?? '') === 'text' ? (string)($element['text'] ?? '') : '',
            $elements
        )));

        if (array_key_exists('pages_count', $expected)) {
            self::assertCount((int)$expected['pages_count'], $pages);
        }
        if (array_key_exists('elements_count', $expected)) {
            self::assertCount((int)$expected['elements_count'], $elements);
        }
        if (array_key_exists('elements_count_min', $expected)) {
            self::assertGreaterThanOrEqual((int)$expected['elements_count_min'], count($elements));
        }
        if (array_key_exists('elements_count_max', $expected)) {
            self::assertLessThanOrEqual((int)$expected['elements_count_max'], count($elements));
        }
        if (isset($expected['meta']) && is_array($expected['meta'])) {
            foreach ($expected['meta'] as $key => $value) {
                self::assertArrayHasKey((string)$key, $meta);
                self::assertSame($value, $meta[(string)$key]);
            }
        }
        if (isset($expected['text_contains']) && is_array($expected['text_contains'])) {
            foreach ($expected['text_contains'] as $needle) {
                self::assertStringContainsString($this->normalizeText((string)$needle), $text);
            }
        }
        if (isset($expected['text_not_contains']) && is_array($expected['text_not_contains'])) {
            foreach ($expected['text_not_contains'] as $needle) {
                self::assertStringNotContainsString($this->normalizeText((string)$needle), $text);
            }
        }
        if (isset($expected['html_contains']) && is_array($expected['html_contains'])) {
            foreach ($expected['html_contains'] as $needle) {
                self::assertStringContainsString((string)$needle, $html);
            }
        }
        if (isset($expected['html_not_contains']) && is_array($expected['html_not_contains'])) {
            foreach ($expected['html_not_contains'] as $needle) {
                self::assertStringNotContainsString((string)$needle, $html);
            }
        }
        if (isset($expected['type_counts']) && is_array($expected['type_counts'])) {
            $counts = [];
            foreach ($elements as $element) {
                $type = (string)($element['type'] ?? '');
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            foreach ($expected['type_counts'] as $typeKey => $value) {
                $typeKey = (string)$typeKey;
                if (str_ends_with($typeKey, '_min')) {
                    $type = substr($typeKey, 0, -4);
                    self::assertGreaterThanOrEqual((int)$value, (int)($counts[$type] ?? 0), sprintf('type count min failed for %s', $type));
                    continue;
                }
                if (str_ends_with($typeKey, '_max')) {
                    $type = substr($typeKey, 0, -4);
                    self::assertLessThanOrEqual((int)$value, (int)($counts[$type] ?? 0), sprintf('type count max failed for %s', $type));
                    continue;
                }
                self::assertSame((int)$value, (int)($counts[$typeKey] ?? 0), sprintf('type count failed for %s', $typeKey));
            }
        }
    }

    /**
     * @param array<string,mixed> $salesDocument
     * @param array<string,mixed> $expected
     */
    private function assertSalesDocument(array $salesDocument, array $expected): void
    {
        if ($expected === []) {
            return;
        }

        $text = $this->normalizeText(implode(' ', array_map('strval', $salesDocument['text'] ?? [])));
        $images = is_array($salesDocument['images'] ?? null) ? $salesDocument['images'] : [];

        if (isset($expected['text_contains']) && is_array($expected['text_contains'])) {
            foreach ($expected['text_contains'] as $needle) {
                self::assertStringContainsString($this->normalizeText((string)$needle), $text);
            }
        }
        if (isset($expected['text_not_contains']) && is_array($expected['text_not_contains'])) {
            foreach ($expected['text_not_contains'] as $needle) {
                self::assertStringNotContainsString($this->normalizeText((string)$needle), $text);
            }
        }
        if (array_key_exists('image_count', $expected)) {
            self::assertCount((int)$expected['image_count'], $images);
        }
        if (array_key_exists('image_count_min', $expected)) {
            self::assertGreaterThanOrEqual((int)$expected['image_count_min'], count($images));
        }
        if (array_key_exists('image_count_max', $expected)) {
            self::assertLessThanOrEqual((int)$expected['image_count_max'], count($images));
        }
        if (isset($expected['quotation']) && is_array($expected['quotation'])) {
            $quotation = is_array($salesDocument['quotation'] ?? null) ? $salesDocument['quotation'] : null;
            self::assertIsArray($quotation, 'Expected quotation model to be extracted.');
            if (isset($expected['quotation']['schema'])) {
                self::assertSame($expected['quotation']['schema'], $quotation['schema'] ?? null);
            }
            if (isset($expected['quotation']['line_items'])) {
                self::assertSame($expected['quotation']['line_items'], $quotation['line_items'] ?? null);
            }
            if (array_key_exists('subtotal', $expected['quotation'])) {
                self::assertSame($expected['quotation']['subtotal'], $quotation['subtotal'] ?? null);
            }
            if (isset($expected['quotation']['totals'])) {
                self::assertSame($expected['quotation']['totals'], $quotation['totals'] ?? null);
            }
        }
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
