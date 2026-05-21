<?php

declare(strict_types=1);

namespace Launix\PdfToData\Tests\Support;

final class SyntheticPdfFactory
{
    /**
     * @param array<int,array{header?:string,body?:list<string>,footer?:string}> $pages
     */
    public static function multiPageDocument(array $pages): string
    {
        $objects = [];
        $pageObjectIds = [];
        $fontObjectId = 3;
        $nextObjectId = 4;

        $objects[1] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[2] = '2 0 obj << /Type /Pages /Kids [PAGES] /Count ' . count($pages) . ' >> endobj';
        $objects[$fontObjectId] = '3 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';

        foreach ($pages as $page) {
            $pageObjectId = $nextObjectId++;
            $contentObjectId = $nextObjectId++;
            $pageObjectIds[] = $pageObjectId;

            $streamParts = [];
            if (($page['header'] ?? '') !== '') {
                $streamParts[] = self::textBlock((string)$page['header'], 72, 770, 14);
            }

            foreach (array_values($page['body'] ?? []) as $index => $line) {
                $streamParts[] = self::textBlock((string)$line, 72, 640 - ($index * 26), 14);
            }

            if (($page['footer'] ?? '') !== '') {
                $streamParts[] = self::textBlock((string)$page['footer'], 72, 26, 14);
            }
            $stream = implode(' ', $streamParts);

            $objects[$pageObjectId] = sprintf(
                '%d 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >> endobj',
                $pageObjectId,
                $fontObjectId,
                $contentObjectId
            );
            $objects[$contentObjectId] = sprintf(
                "%d 0 obj << /Length %d >> stream\n%s\nendstream endobj",
                $contentObjectId,
                strlen($stream),
                $stream
            );
        }

        $kids = implode(' ', array_map(static fn(int $id): string => sprintf('%d 0 R', $id), $pageObjectIds));
        $objects[2] = str_replace('PAGES', $kids, $objects[2]);
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $objectId => $object) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($objectId = 1; $objectId <= max(array_keys($objects)); $objectId++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId] ?? 0);
        }

        $pdf .= "trailer << /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private static function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function textBlock(string $text, int $x, int $y, int $fontSize): string
    {
        return sprintf(
            'BT /F1 %d Tf 1 0 0 1 %d %d Tm (%s) Tj ET',
            $fontSize,
            $x,
            $y,
            self::escapePdfText($text)
        );
    }
}
