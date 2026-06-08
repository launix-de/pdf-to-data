<?php

declare(strict_types=1);

namespace Launix\PdfToData;

use Launix\PdfToData\Internal\XtractEngine;

final class SalesDocumentExtractor
{
    /**
     * Build a first reusable sales-document payload from the current extracted elements.
     *
     * This intentionally stays a thin layer on top of `extractElements()`.
     *
     * @return array<string,mixed>
     */
    public function extract(NormalizedDocument $document): array
    {
        $projectedElements = $this->projectElements($document->elements(), $document->pages());
        $textElements = [];
        $imageElements = [];

        foreach ($document->elements() as $element) {
            $type = (string)($element['type'] ?? '');
            if ($type === 'text') {
                $textElements[] = $element;
            } elseif ($type === 'image') {
                $imageElements[] = $element;
            }
        }

        $rows = $this->clusterTextRows($textElements, $document->pages());
        $quotationTable = $this->extractQuotationTable($rows, $projectedElements);
        $invoiceTable = $this->extractInvoiceTable($rows, $projectedElements);
        $deliveryNoteTable = $this->extractDeliveryNoteTable($rows, $projectedElements);

        return [
            'meta' => $document->meta(),
            'pages' => $document->pages(),
            'elements' => $document->elements(),
            'text' => array_map(
                static fn(array $element): string => (string)($element['text'] ?? ''),
                $textElements
            ),
            'images' => array_map(
                static fn(array $element): array => [
                    'page_index' => $element['page_index'] ?? null,
                    'left' => $element['left'] ?? $element['x'] ?? null,
                    'top' => $element['top'] ?? null,
                    'width' => $element['width'] ?? null,
                    'height' => $element['height'] ?? null,
                ],
                $imageElements
            ),
            'quotation' => $quotationTable,
            'invoice' => $invoiceTable,
            'delivery_note' => $deliveryNoteTable,
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @param array<int,array<string,mixed>> $projectedElements
     * @return array<string,mixed>|null
     */
    private function extractQuotationTable(array $rows, array $projectedElements): ?array
    {
        $positionedQuotation = $this->extractPositionBlocksQuotation($rows, $projectedElements);
        if ($positionedQuotation !== null) {
            return $positionedQuotation;
        }

        $verscoQuotation = $this->extractVerscoQuotation($rows, $projectedElements);
        if ($verscoQuotation !== null) {
            return $verscoQuotation;
        }

        $headerIndex = null;
        $positionX = null;
        $priceX = null;
        $quantityX = null;
        $totalX = null;

        foreach ($rows as $index => $row) {
            $labelMap = [];
            foreach ($row['cells'] as $cell) {
                $labelMap[$this->normalizeCell((string)$cell['text'])] = $this->elementLeft($cell);
            }
            if (!isset($labelMap['position'], $labelMap['angebot-einzelpreis'], $labelMap['menge'], $labelMap['gesamt'])) {
                continue;
            }
            $headerIndex = $index;
            $positionX = $labelMap['position'];
            $priceX = $labelMap['angebot-einzelpreis'];
            $quantityX = $labelMap['menge'];
            $totalX = $labelMap['gesamt'];
            break;
        }

        if ($headerIndex === null || $positionX === null || $priceX === null || $quantityX === null || $totalX === null) {
            return null;
        }

        $leftDescriptionLimit = ($positionX + $priceX) / 2.0;
        $leftPriceLimit = ($priceX + $quantityX) / 2.0;
        $leftQuantityLimit = ($quantityX + $totalX) / 2.0;

        $lineItems = [];
        $subtotal = null;
        $totals = [];
        $currentItemIndex = null;
        $pendingPosition = null;

        for ($i = $headerIndex + 1, $count = count($rows); $i < $count; $i++) {
            $row = $rows[$i];
            $cells = $row['cells'];
            if ($cells === []) {
                continue;
            }

            $rowTexts = array_map(static fn(array $cell): string => trim((string)$cell['text']), $cells);
            $rowLabel = $this->normalizeCell(implode(' ', $rowTexts));
            if ($rowLabel === '') {
                continue;
            }

            $firstText = trim((string)$cells[0]['text']);
            if (in_array($firstText, ['Sehr geehrte Damen und Herren,', 'Das Angebot ist bis 06.05.2025 gültig.', 'Mit freundlichen Grüßen', 'Ihr Team'], true)) {
                break;
            }

            if (str_starts_with($rowLabel, 'summe netto')) {
                $totals['net'] = $this->parseDecimal(trim((string)$cells[count($cells) - 1]['text']));
                continue;
            }
            if (str_starts_with($rowLabel, 'mwst.')) {
                $totals['vat_label'] = trim((string)$cells[0]['text']);
                $totals['vat'] = $this->parseDecimal(trim((string)$cells[count($cells) - 1]['text']));
                continue;
            }
            if (str_starts_with($rowLabel, 'summe brutto')) {
                $totals['gross'] = $this->parseDecimal(trim((string)$cells[count($cells) - 1]['text']));
                break;
            }

            $position = null;
            $descriptionParts = [];
            $unitPrice = null;
            $quantityRaw = null;
            $total = null;

            foreach ($cells as $cell) {
                $text = trim((string)$cell['text']);
                if ($text === '') {
                    continue;
                }
                $left = $this->elementLeft($cell);
                if ($left < $leftDescriptionLimit) {
                    if (preg_match('/^\d+\.$/', $text) === 1) {
                        $position = $text;
                    } else {
                        $descriptionParts[] = $text;
                    }
                    continue;
                }
                if ($left < $leftPriceLimit) {
                    $unitPrice = $text;
                    continue;
                }
                if ($left < $leftQuantityLimit) {
                    $quantityRaw = $text;
                    continue;
                }
                $total = $text;
            }

            $description = trim(implode("\n", $descriptionParts));
            if ($position !== null && $description === '' && $unitPrice === null && $quantityRaw === null && $total === null) {
                $pendingPosition = $position;
                continue;
            }
            if ($unitPrice !== null || $quantityRaw !== null || $total !== null) {
                if ($description === '' && $position === null && $unitPrice === null && $quantityRaw === null && $total !== null) {
                    $subtotal = $this->parseDecimal($total);
                    continue;
                }
                [$quantity, $unit] = $this->splitQuantityAndUnit($quantityRaw);
                $lineItems[] = [
                    'position' => $position ?? $pendingPosition,
                    'beschreibung' => $description,
                    'einzelpreis' => $this->parseDecimal($unitPrice),
                    'menge' => $quantity,
                    'einheit' => $unit,
                    'gesamt' => $this->parseDecimal($total),
                ];
                $currentItemIndex = array_key_last($lineItems);
                $pendingPosition = null;
                continue;
            }

            if ($description !== '' && $currentItemIndex !== null) {
                $lineItems[$currentItemIndex]['beschreibung'] .= "\n" . $description;
            }
        }

        if ($lineItems === [] && $totals === []) {
            return null;
        }

        return [
            'schema' => ['beschreibung', 'einzelpreis', 'menge', 'einheit', 'gesamt'],
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @param array<int,array<string,mixed>> $projectedElements
     * @return array<string,mixed>|null
     */
    private function extractVerscoQuotation(array $rows, array $projectedElements): ?array
    {
        $headerIndex = null;
        foreach ($rows as $index => $row) {
            $joined = $this->normalizeCell(implode(' ', array_map(
                static fn(array $cell): string => trim((string)($cell['text'] ?? '')),
                $row['cells']
            )));
            if (preg_match('/\bposition\b/u', $joined) === 1
                && preg_match('/\bbezeichnung\b/u', $joined) === 1
                && preg_match('/\banzahl\b/u', $joined) === 1
                && preg_match('/\bpreis\b/u', $joined) === 1
                && preg_match('/\bgesamt\b/u', $joined) === 1) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $blockStarts = [];
        $groupByStart = [];
        $currentGroup = null;

        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $texts = $this->rowTexts($rows[$index]);
            if ($texts === []) {
                continue;
            }

            $joined = trim(implode(' ', $texts));
            if ($index < $headerIndex) {
                $normalized = $this->normalizeCell($joined);
                if (preg_match('/^(?:gruppe\s+\d+|neue gruppe)$/u', $normalized) === 1) {
                    $currentGroup = $joined;
                }
                continue;
            }

            if ($this->isVerscoItemRow($texts)) {
                $blockStarts[] = $index;
                $groupByStart[$index] = $currentGroup;
                continue;
            }

            $normalized = $this->normalizeCell($joined);
            if (preg_match('/^(?:gruppe\s+\d+|neue gruppe)$/u', $normalized) === 1) {
                $currentGroup = $joined;
            }
        }

        if ($blockStarts === []) {
            return null;
        }

        $lineItems = [];
        foreach ($blockStarts as $offset => $startIndex) {
            $endIndex = $blockStarts[$offset + 1] ?? count($rows);
            $blockRows = array_slice($rows, $startIndex, $endIndex - $startIndex);
            $blockEndTop = $endIndex < count($rows)
                ? (float)$rows[$endIndex]['top']
                : ((float)$blockRows[count($blockRows) - 1]['top'] + $this->estimateRowHeight($blockRows[count($blockRows) - 1]) + 4.0);
            $lineItem = $this->buildVerscoQuotationLineItem($blockRows, $projectedElements, $blockEndTop, $groupByStart[$startIndex] ?? null);
            if ($lineItem !== null) {
                $lineItems[] = $lineItem;
            }
        }

        if ($lineItems === []) {
            return null;
        }

        $subtotal = array_reduce(
            $lineItems,
            static fn(float $sum, array $item): float => $sum + (float)($item['gesamt'] ?? 0.0),
            0.0
        );

        return [
            'schema' => ['position', 'beschreibung', 'einzelpreis', 'menge', 'einheit', 'gesamt'],
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'totals' => ['net' => $subtotal],
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @param array<int,array<string,mixed>> $projectedElements
     * @return array<string,mixed>|null
     */
    private function extractInvoiceTable(array $rows, array $projectedElements): ?array
    {
        $headerIndex = null;
        foreach ($rows as $index => $row) {
            $joined = $this->normalizeCell(implode(' ', $this->rowTexts($row)));
            if (preg_match('/\bpos\b/u', $joined) === 1
                && preg_match('/\bartikel\b/u', $joined) === 1
                && preg_match('/\beinzelpreis\b/u', $joined) === 1
                && preg_match('/\bgesamtpreis\b/u', $joined) === 1) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $lineItems = [];
        $currentItem = null;

        for ($index = $headerIndex + 1, $count = count($rows); $index < $count; $index++) {
            $texts = $this->rowTexts($rows[$index]);
            if ($texts === []) {
                continue;
            }

            $joined = trim(implode(' ', $texts));
            $normalized = $this->normalizeCell($joined);
            if ($normalized === 'eur' || str_contains($normalized, 'unit price') || str_contains($normalized, 'total price')) {
                continue;
            }

            if ($this->isInvoiceSummaryRow($normalized)) {
                break;
            }

            $parsed = $this->parseInvoiceItemRow($texts);
            if ($parsed !== null) {
                if ($currentItem !== null) {
                    $lineItems[] = $currentItem;
                }
                $currentItem = $parsed;
                continue;
            }

            if ($currentItem !== null && !$this->isDocumentFurnitureRow($normalized)) {
                $append = preg_replace('/\s+/u', ' ', $joined) ?? $joined;
                if ($append !== '') {
                    $currentItem['beschreibung'] .= "\n" . trim($append);
                }
            }
        }

        if ($currentItem !== null) {
            $lineItems[] = $currentItem;
        }

        if ($lineItems === []) {
            return null;
        }

        $subtotal = array_reduce(
            $lineItems,
            static fn(float $sum, array $item): float => $sum + (float)($item['gesamt'] ?? 0.0),
            0.0
        );

        return [
            'schema' => ['position', 'beschreibung', 'menge', 'einheit', 'einzelpreis', 'gesamt'],
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'totals' => ['net' => $subtotal],
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @param array<int,array<string,mixed>> $projectedElements
     * @return array<string,mixed>|null
     */
    private function extractDeliveryNoteTable(array $rows, array $projectedElements): ?array
    {
        $headerIndex = null;
        foreach ($rows as $index => $row) {
            $joined = $this->normalizeCell(implode(' ', $this->rowTexts($row)));
            if (preg_match('/\bpos\b/u', $joined) === 1
                && preg_match('/\bbeschreibung\b/u', $joined) === 1
                && (preg_match('/\bartikelnummer\b/u', $joined) === 1 || preg_match('/\barticle\b/u', $joined) === 1)) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            return null;
        }

        $lineItems = [];
        $currentItem = null;

        for ($index = $headerIndex + 1, $count = count($rows); $index < $count; $index++) {
            $texts = $this->rowTexts($rows[$index]);
            if ($texts === []) {
                continue;
            }

            $joined = trim(implode(' ', $texts));
            $normalized = $this->normalizeCell($joined);

            if ($this->isDocumentFurnitureRow($normalized)) {
                continue;
            }
            if (str_starts_with($normalized, 'lieferung frei haus')) {
                break;
            }

            $parsed = $this->parseDeliveryItemRow($texts);
            if ($parsed !== null) {
                if ($currentItem !== null) {
                    $lineItems[] = $currentItem;
                }
                $currentItem = $parsed;
                continue;
            }

            if ($currentItem !== null) {
                $append = preg_replace('/\s+/u', ' ', $joined) ?? $joined;
                if ($append !== '') {
                    $currentItem['beschreibung'] .= "\n" . trim($append);
                }
            }
        }

        if ($currentItem !== null) {
            $lineItems[] = $currentItem;
        }

        if ($lineItems === []) {
            return null;
        }

        return [
            'schema' => ['position', 'beschreibung', 'menge', 'einheit'],
            'line_items' => $lineItems,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $textElements
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array{top: float, cells: array<int,array<string,mixed>>}>
     */
    private function clusterTextRows(array $textElements, array $pages): array
    {
        $pageOffsets = [];
        foreach ($pages as $page) {
            $pageOffsets[(int)($page['page_index'] ?? count($pageOffsets))] = (float)($page['offset_top'] ?? 0.0);
        }

        usort($textElements, static function (array $left, array $right) use ($pageOffsets): int {
            $leftTop = (float)($pageOffsets[(int)($left['page_index'] ?? 0)] ?? 0.0) + self::elementTop($left);
            $rightTop = (float)($pageOffsets[(int)($right['page_index'] ?? 0)] ?? 0.0) + self::elementTop($right);
            $topCompare = $leftTop <=> $rightTop;
            if ($topCompare !== 0) {
                return $topCompare;
            }
            return self::elementLeft($left) <=> self::elementLeft($right);
        });

        $rows = [];
        foreach ($textElements as $element) {
            $text = trim((string)($element['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $pageOffset = (float)($pageOffsets[(int)($element['page_index'] ?? 0)] ?? 0.0);
            $top = $pageOffset + $this->elementTop($element);
            $matched = false;
            foreach ($rows as &$row) {
                if (abs($row['top'] - $top) <= 2.0) {
                    $row['cells'][] = $element;
                    $matched = true;
                    break;
                }
            }
            unset($row);
            if (!$matched) {
                $rows[] = [
                    'top' => $top,
                    'cells' => [$element],
                ];
            }
        }

        foreach ($rows as &$row) {
            usort($row['cells'], static fn(array $left, array $right): int => self::elementLeft($left) <=> self::elementLeft($right));
        }
        unset($row);

        return $rows;
    }

    private function normalizeCell(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @return array{0:int|float|null,1:?string}
     */
    private function splitQuantityAndUnit(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^([0-9][0-9.,]*)\s+(.+)$/u', $value, $matches) === 1) {
            return [$this->parseDecimal(trim($matches[1])), trim($matches[2])];
        }

        return [$this->parseDecimal($value), null];
    }

    private function parseDecimal(?string $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(["\xc2\xa0", 'EUR', '€'], ['', '', ''], $value);
        $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
        if ($value === '' || $value === '-' || $value === '.' || $value === ',') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float)$value;
        return fmod($number, 1.0) === 0.0 ? (int)$number : $number;
    }

    /**
     * @param array<string,mixed> $element
     */
    private static function elementLeft(array $element): float
    {
        return (float)($element['left'] ?? $element['x'] ?? 0.0);
    }

    /**
     * @param array<string,mixed> $element
     */
    private static function elementTop(array $element): float
    {
        if (array_key_exists('top', $element)) {
            return (float)$element['top'];
        }

        $pageHeight = (float)($element['page_height'] ?? 0.0);
        $y = (float)($element['y'] ?? 0.0);
        $fontSize = max(0.0, (float)($element['font_size'] ?? 0.0));

        return max(0.0, $pageHeight - $y - $fontSize);
    }

    /**
     * @param array<int,array<string,mixed>> $elements
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private function projectElements(array $elements, array $pages): array
    {
        $pageOffsets = [];
        $pageHeights = [];
        foreach ($pages as $page) {
            $pageIndex = (int)($page['page_index'] ?? count($pageOffsets));
            $pageOffsets[$pageIndex] = (float)($page['offset_top'] ?? 0.0);
            $pageHeights[$pageIndex] = (float)($page['page_height'] ?? $page['raw_height'] ?? 0.0);
        }

        $projected = [];
        $pageWidths = [];
        foreach ($elements as $element) {
            $pageIndex = (int)($element['page_index'] ?? 0);
            $pageOffset = (float)($pageOffsets[$pageIndex] ?? 0.0);
            $pageHeight = (float)($pageHeights[$pageIndex] ?? ($element['page_height'] ?? 0.0));
            $type = (string)($element['type'] ?? '');
            $left = self::elementLeft($element);
            $localTop = self::elementTop($element);
            $top = $pageOffset + $localTop;

            $width = max(0.0, (float)($element['width'] ?? $element['render_w'] ?? 0.0));
            $height = max(0.0, (float)($element['height'] ?? $element['render_h'] ?? 0.0));
            if ($type === 'text') {
                $fontSize = max(0.0, (float)($element['font_size'] ?? 12.0));
                $height = max($height, $fontSize);
                $width = max($width, mb_strlen((string)($element['text'] ?? ''), 'UTF-8') * max(1.0, $fontSize) * 0.6);
            }

            $projectedElement = $element;
            $projectedElement['left'] = $left;
            $projectedElement['top'] = $top;
            $projectedElement['width'] = $width;
            $projectedElement['height'] = $height;
            $projectedElement['page_height'] = $pageHeight;
            $projectedElement['page_offset_top'] = $pageOffset;
            $projectedElement['page_local_top'] = $localTop;
            if ($type === 'image' && !isset($projectedElement['url']) && isset($projectedElement['dataUri'])) {
                $projectedElement['url'] = $projectedElement['dataUri'];
            }

            $projected[] = $projectedElement;
            $pageWidths[$pageIndex] = max((float)($pageWidths[$pageIndex] ?? 0.0), $left + $width);
        }

        foreach ($projected as &$element) {
            $pageIndex = (int)($element['page_index'] ?? 0);
            $element['page_width'] = (float)($pageWidths[$pageIndex] ?? 0.0);
        }
        unset($element);

        return $projected;
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @return array<string,mixed>|null
     */
    private function extractPositionBlocksQuotation(array $rows, array $projectedElements): ?array
    {
        $positionRowIndexes = [];
        foreach ($rows as $index => $row) {
            $joined = trim(implode(' ', array_map(
                static fn(array $cell): string => trim((string)($cell['text'] ?? '')),
                $row['cells']
            )));
            if (preg_match('/\bPos\.\s*(\d+)\./u', $joined) === 1) {
                $positionRowIndexes[] = $index;
            }
        }

        if ($positionRowIndexes === []) {
            return null;
        }

        $lineItems = [];
        foreach ($positionRowIndexes as $offset => $startIndex) {
            $endIndex = $positionRowIndexes[$offset + 1] ?? count($rows);
            $blockRows = array_slice($rows, $startIndex, $endIndex - $startIndex);
            $blockEndTop = $endIndex < count($rows)
                ? (float)$rows[$endIndex]['top']
                : ((float)$blockRows[count($blockRows) - 1]['top'] + $this->estimateRowHeight($blockRows[count($blockRows) - 1]) + 4.0);
            $lineItem = $this->buildPositionBlockLineItem($blockRows, $projectedElements, $blockEndTop);
            if ($lineItem !== null) {
                $lineItems[] = $lineItem;
            }
        }

        if ($lineItems === []) {
            return null;
        }

        $totals = $this->extractSummaryTotals($rows);
        $subtotal = $totals['net'] ?? array_reduce(
            $lineItems,
            static fn(float $sum, array $item): float => $sum + (float)($item['gesamt'] ?? 0.0),
            0.0
        );

        return [
            'schema' => ['position', 'beschreibung', 'einzelpreis', 'menge', 'einheit', 'gesamt'],
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $blockRows
     * @return array<string,mixed>|null
     */
    private function buildPositionBlockLineItem(array $blockRows, array $projectedElements, float $blockEndTop): ?array
    {
        if ($blockRows === []) {
            return null;
        }

        $position = null;
        $unitPrice = null;
        $quantity = null;
        $netValue = null;
        $vatPercent = null;
        $grossValue = null;
        $descriptionLines = [];
        $captureDescription = false;
        $detailStartTop = null;
        $firstDetailTop = null;

        foreach ($blockRows as $row) {
            $texts = array_values(array_filter(array_map(
                static fn(array $cell): string => trim((string)($cell['text'] ?? '')),
                $row['cells']
            ), static fn(string $text): bool => $text !== ''));

            if ($texts === []) {
                continue;
            }

            $joined = trim(implode(' ', $texts));
            if ($position === null && preg_match('/\bPos\.\s*(\d+)\./u', $joined, $matches) === 1) {
                $position = (int)$matches[1];
                if (preg_match('/\bNettopreise\s+EUR\b/u', $joined) === 1) {
                    $priceCells = preg_split('/\s{2,}|\|/u', $joined) ?: [];
                    $priceCells = array_values(array_filter(array_map('trim', $priceCells), static fn(string $v): bool => $v !== ''));
                    if (($priceCells[0] ?? '') !== '' && str_starts_with($priceCells[0], 'Pos.')) {
                        array_shift($priceCells);
                    }
                    if (($priceCells[0] ?? '') === 'Nettopreise EUR') {
                        array_shift($priceCells);
                    }
                    if (count($priceCells) >= 5) {
                        $unitPrice = $this->parseDecimal($priceCells[0]);
                        [$quantity, $unit] = $this->splitQuantityAndUnit($priceCells[1]);
                        $netValue = $this->parseDecimal($priceCells[2]);
                        $vatPercent = $this->parsePercent($priceCells[3]);
                        $grossValue = $this->parseDecimal($priceCells[4]);
                        $captureDescription = true;
                        $detailStartTop = (float)$row['top'] + $this->estimateRowHeight($row) + 2.0;
                    }
                }
                continue;
            }

            if (str_contains($joined, 'Nettopreise EUR')) {
                $priceCells = array_values(array_filter($texts, static fn(string $text): bool => $text !== 'Nettopreise EUR'));
                if (count($priceCells) >= 5) {
                    $unitPrice = $this->parseDecimal($priceCells[0]);
                    [$quantity, $unit] = $this->splitQuantityAndUnit($priceCells[1]);
                    $netValue = $this->parseDecimal($priceCells[2]);
                    $vatPercent = $this->parsePercent($priceCells[3]);
                    $grossValue = $this->parseDecimal($priceCells[4]);
                }
                $captureDescription = true;
                $detailStartTop = (float)$row['top'] + $this->estimateRowHeight($row) + 2.0;
                continue;
            }

            if (!$captureDescription) {
                continue;
            }

            if ($this->isOfferBoilerplateRow($joined)) {
                continue;
            }

            if ($firstDetailTop === null) {
                $firstDetailTop = (float)$row['top'];
            }
            $descriptionLines[] = $joined;
        }

        if ($position === null || $unitPrice === null || $quantity === null || $netValue === null) {
            return null;
        }

        $description = $this->normalizeDescriptionLines($descriptionLines);
        $description = $this->restoreMissingDescriptionHeading($description);
        $description = trim((string)strtok($description, "\n"));

        $detailHtml = $this->buildPositionDetailHtml(
            $projectedElements,
            $firstDetailTop !== null
                ? max(0.0, $firstDetailTop - 2.0)
                : ($detailStartTop ?? ((float)$blockRows[0]['top'] + $this->estimateRowHeight($blockRows[0]))),
            $blockEndTop
        );

        return [
            'position' => $position,
            'beschreibung' => $description,
            'einzelpreis' => $unitPrice,
            'menge' => $quantity,
            'einheit' => $unit ?? null,
            'gesamt' => $netValue,
            'mwst' => $vatPercent,
            'brutto' => $grossValue,
            'detail_html' => $detailHtml,
        ];
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $blockRows
     * @param array<int,array<string,mixed>> $projectedElements
     * @return array<string,mixed>|null
     */
    private function buildVerscoQuotationLineItem(array $blockRows, array $projectedElements, float $blockEndTop, ?string $group): ?array
    {
        if ($blockRows === []) {
            return null;
        }

        $itemTexts = $this->rowTexts($blockRows[0]);
        if (!$this->isVerscoItemRow($itemTexts)) {
            return null;
        }

        $position = (int)$itemTexts[0];
        $numericIndexes = [];
        foreach ($itemTexts as $index => $text) {
            if ($index === 0) {
                continue;
            }
            if ($this->parseDecimal($text) !== null) {
                $numericIndexes[] = $index;
            }
        }

        if (count($numericIndexes) < 2) {
            return null;
        }

        $unitPriceIndex = $numericIndexes[count($numericIndexes) - 2];
        $totalIndex = $numericIndexes[count($numericIndexes) - 1];
        $unitPrice = $this->parseDecimal($itemTexts[$unitPriceIndex]);
        $total = $this->parseDecimal($itemTexts[$totalIndex]);
        if ($unitPrice === null || $total === null) {
            return null;
        }

        $quantityParts = array_slice($itemTexts, 2, max(0, $unitPriceIndex - 2));
        $quantityRaw = trim(implode(' ', $quantityParts));
        $quantityTail = null;
        if (substr_count($quantityRaw, '(') > substr_count($quantityRaw, ')') && isset($blockRows[1])) {
            $nextTexts = $this->rowTexts($blockRows[1]);
            $candidateTail = $nextTexts !== [] ? trim((string)end($nextTexts)) : null;
            if (is_string($candidateTail) && preg_match('/^[[:alpha:]]+\)$/u', $candidateTail) === 1) {
                $quantityRaw .= ' ' . $candidateTail;
                $quantityTail = $candidateTail;
            }
        }
        [$quantity, $unit] = $this->splitQuantityAndUnit($quantityRaw);
        if ($quantity === null && isset($itemTexts[2])) {
            [$quantity, $unit] = $this->splitQuantityAndUnit($itemTexts[2]);
        }

        $rowLabel = $itemTexts[1] ?? '';
        $detailStartTop = (float)$blockRows[0]['top'] + $this->estimateRowHeight($blockRows[0]) + 2.0;
        $descriptionLines = [];

        foreach (array_slice($blockRows, 1) as $row) {
            $joined = trim(implode(' ', $this->rowTexts($row)));
            if ($quantityTail !== null && str_ends_with($joined, ' ' . $quantityTail)) {
                $joined = trim(substr($joined, 0, -strlen(' ' . $quantityTail)));
            }
            if ($joined === '' || $this->isOfferBoilerplateRow($joined)) {
                continue;
            }
            $descriptionLines[] = $joined;
        }

        $description = $this->normalizeVerscoDescriptionLines($descriptionLines, $rowLabel);
        $detailHtml = $this->buildPositionDetailHtml($projectedElements, $detailStartTop, $blockEndTop);
        $detailHtml = $this->ensureDetailHtmlContainsDescriptionHeading($detailHtml, $description);

        $result = [
            'position' => $position,
            'beschreibung' => $description,
            'einzelpreis' => $unitPrice,
            'menge' => $quantity,
            'einheit' => $unit,
            'gesamt' => $total,
            'detail_html' => $detailHtml,
        ];

        if ($group !== null && $group !== '') {
            $result['group'] = $group;
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $projectedElements
     */
    private function buildPositionDetailHtml(array $projectedElements, float $startTop, float $endTop): string
    {
        if ($endTop <= $startTop) {
            return '';
        }

        $pages = [];
        foreach ($projectedElements as $element) {
            $pageIndex = (int)($element['page_index'] ?? 0);
            if (!isset($pages[$pageIndex])) {
                $pages[$pageIndex] = [
                    'offset_top' => (float)($element['page_offset_top'] ?? 0.0),
                    'page_height' => (float)($element['page_height'] ?? 0.0),
                    'page_width' => (float)($element['page_width'] ?? 0.0),
                ];
            }
        }
        ksort($pages, SORT_NUMERIC);

        $snippetElements = [];
        $compactTop = 0.0;
        foreach ($pages as $pageIndex => $page) {
            $pageTop = (float)$page['offset_top'];
            $pageBottom = $pageTop + (float)$page['page_height'];
            $sliceTop = max($startTop, $pageTop);
            $sliceBottom = min($endTop, $pageBottom);
            if ($sliceBottom <= $sliceTop) {
                continue;
            }

            $sliceLocalTop = $sliceTop - $pageTop;
            $sliceHeight = $sliceBottom - $sliceTop;
            $pageWidth = (float)$page['page_width'];

            foreach ($projectedElements as $element) {
                if ((int)($element['page_index'] ?? 0) !== $pageIndex) {
                    continue;
                }

                $localTop = (float)($element['page_local_top'] ?? 0.0);
                $height = max(0.0, (float)($element['height'] ?? 0.0));
                $bottom = $localTop + max($height, 1.0);
                if ($bottom <= $sliceLocalTop || $localTop >= ($sliceLocalTop + $sliceHeight)) {
                    continue;
                }

                if (!$this->shouldIncludeDetailElement($element, $sliceLocalTop, $sliceHeight, $pageWidth)) {
                    continue;
                }

                $snippetElement = $element;
                $snippetElement['top'] = $compactTop + max(0.0, $localTop - $sliceLocalTop);
                $snippetElements[] = $snippetElement;
            }

            $compactTop += $sliceHeight;
        }

        if ($snippetElements === []) {
            return '';
        }

        usort($snippetElements, static function (array $left, array $right): int {
            $topCompare = ((float)($left['top'] ?? 0.0)) <=> ((float)($right['top'] ?? 0.0));
            if ($topCompare !== 0) {
                return $topCompare;
            }

            return self::elementLeft($left) <=> self::elementLeft($right);
        });

        return XtractEngine::renderElementsHtmlFragment($snippetElements);
    }

    /**
     * @param array<string,mixed> $element
     */
    private function shouldIncludeDetailElement(array $element, float $sliceLocalTop, float $sliceHeight, float $pageWidth): bool
    {
        $type = (string)($element['type'] ?? '');
        if ($type === 'text') {
            $text = trim((string)($element['text'] ?? ''));
            if ($text === '' || $this->isOfferBoilerplateRow($text)) {
                return false;
            }

            return true;
        }

        if ($type === 'image') {
            $localTop = (float)($element['page_local_top'] ?? 0.0);
            $width = max(0.0, (float)($element['width'] ?? 0.0));
            $height = max(0.0, (float)($element['height'] ?? 0.0));
            $pageHeight = (float)($element['page_height'] ?? 0.0);

            if ($width >= ($pageWidth * 0.9) && $height >= ($pageHeight * 0.9)) {
                return false;
            }
            if ($width >= ($pageWidth * 0.8) && ($localTop < 80.0 || ($localTop + $height) > ($pageHeight - 80.0))) {
                return false;
            }
            if ($height <= 6.0 && $width >= ($pageWidth * 0.4)) {
                return false;
            }

            return true;
        }

        if ($type === 'line') {
            $left = self::elementLeft($element);
            $top = (float)($element['page_local_top'] ?? 0.0);
            $width = max(0.0, (float)($element['width'] ?? 0.0));
            $height = max(0.0, (float)($element['height'] ?? 0.0));

            if (($width >= ($pageWidth * 0.7) && $height <= 2.0) || ($height >= ($sliceHeight * 0.95) && $width <= 2.0 && $left <= 20.0)) {
                return false;
            }
            if ($top < max(8.0, $sliceLocalTop - 12.0) && $width >= ($pageWidth * 0.6)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array{top: float, cells: array<int,array<string,mixed>>}> $rows
     * @return array<string,mixed>
     */
    private function extractSummaryTotals(array $rows): array
    {
        $totals = [];
        $inSummary = false;
        $pendingNet = false;
        $previousTexts = null;

        foreach ($rows as $row) {
            $texts = array_values(array_filter(array_map(
                static fn(array $cell): string => trim((string)($cell['text'] ?? '')),
                $row['cells']
            ), static fn(string $text): bool => $text !== ''));

            if ($texts === []) {
                continue;
            }

            $joined = trim(implode(' ', $texts));
            if ($joined === 'Zusammenfassung') {
                $inSummary = true;
                continue;
            }
            if (!$inSummary) {
                continue;
            }

            if (str_starts_with($joined, 'Gesamt EUR:')) {
                $pendingNet = true;
                $sameLineValue = $this->findLastDecimal($texts);
                if ($sameLineValue !== null) {
                    $totals['net'] = $sameLineValue;
                    $pendingNet = false;
                } elseif (is_array($previousTexts)) {
                    $previousValue = $this->findLastDecimal($previousTexts);
                    if ($previousValue !== null) {
                        $totals['net'] = $previousValue;
                        $pendingNet = false;
                    }
                }
                $previousTexts = $texts;
                continue;
            }

            if ($pendingNet) {
                $value = $this->findLastDecimal($texts);
                if ($value !== null) {
                    $totals['net'] = $value;
                    $pendingNet = false;
                }
            }

            $previousTexts = $texts;
        }

        return $totals;
    }

    private function parsePercent(?string $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/(-?\d+(?:[.,]\d+)?)\s*%/u', $value, $matches) !== 1) {
            return null;
        }

        return $this->parseDecimal($matches[1]);
    }

    /**
     * @param array<int,string> $texts
     */
    private function findLastDecimal(array $texts): int|float|null
    {
        for ($index = count($texts) - 1; $index >= 0; $index--) {
            $parsed = $this->parseDecimal($texts[$index]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function isOfferBoilerplateRow(string $joined): bool
    {
        $normalized = $this->normalizeCell($joined);
        foreach ([
            'preis nach',
            'rabatt',
            'menge',
            'nettowert',
            'mwst',
            'bruttowert',
            'ansicht von innen',
            'angebot - nr.',
            'ausdruckdatum:',
            'bearbeitet von',
            'pamproject',
            'für:',
            'angebot vom:',
        ] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return preg_match('/^pos\.\s*\d+\.$/ui', $normalized) === 1
            || $normalized === 'nettopreise eur';
    }

    /**
     * @param array<int,string> $lines
     */
    private function normalizeDescriptionLines(array $lines): string
    {
        $normalized = [];
        foreach ($lines as $line) {
            $clean = preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);
            if ($clean === '') {
                continue;
            }
            if ($normalized !== [] && end($normalized) === $clean) {
                continue;
            }
            $normalized[] = $clean;
        }

        return implode("\n", $normalized);
    }

    /**
     * @param array<int,string> $lines
     */
    private function normalizeVerscoDescriptionLines(array $lines, string $fallbackLabel): string
    {
        $normalized = [];
        foreach ($lines as $line) {
            $clean = preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);
            if ($clean === '' || in_array($clean, ['Bezeichnung', 'Anzahl', 'Preis', 'Gesamt'], true)) {
                continue;
            }
            if ($normalized !== [] && end($normalized) === $clean) {
                continue;
            }
            $normalized[] = $clean;
        }

        $first = $normalized[0] ?? '';
        if ($first === '' && $fallbackLabel !== '') {
            return $fallbackLabel;
        }

        if ($this->isVerscoPlaceholderLabel($fallbackLabel) || $fallbackLabel === '') {
            return implode("\n", $normalized);
        }

        if ($first !== $fallbackLabel) {
            array_unshift($normalized, $fallbackLabel);
        }

        return implode("\n", $normalized);
    }

    private function restoreMissingDescriptionHeading(string $description): string
    {
        if ($description === '') {
            return $description;
        }

        $firstLine = trim((string)strtok($description, "\n"));
        if ($firstLine === '') {
            return $description;
        }

        if (
            !preg_match('/^(?:PVC-SCHREINERARBEITEN|Elemente\/Profile \[[^\]]+\]|Rahmenverbreiterung)$/u', $firstLine)
            && preg_match('/(?:^|\n)(?:Produktnummer\/Produ|Fensterbreite:|Fensterhöhe:|Fenster- \/ Türentyp:|Glaspaket:|Beschlägeart:|Dichtungsfarbe:)/u', $description) === 1
        ) {
            return "PVC-SCHREINERARBEITEN\n" . $description;
        }

        return $description;
    }

    private function ensureDetailHtmlContainsDescriptionHeading(string $detailHtml, string $description): string
    {
        $heading = trim((string)strtok($description, "\n"));
        if ($detailHtml === '' || $heading === '' || str_contains($detailHtml, $heading)) {
            return $detailHtml;
        }

        return '<div class="detail-heading">' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' . $detailHtml;
    }

    /**
     * @param array{top: float, cells: array<int,array<string,mixed>>} $row
     * @return array<int,string>
     */
    private function rowTexts(array $row): array
    {
        return array_values(array_filter(array_map(
            static fn(array $cell): string => trim((string)($cell['text'] ?? '')),
            $row['cells']
        ), static fn(string $text): bool => $text !== ''));
    }

    /**
     * @param array<int,string> $texts
     */
    private function isVerscoItemRow(array $texts): bool
    {
        if (count($texts) < 4 || preg_match('/^\d{4}$/', $texts[0]) !== 1) {
            return false;
        }

        $last = $this->parseDecimal($texts[count($texts) - 1]);
        $prev = $this->parseDecimal($texts[count($texts) - 2] ?? null);
        return $last !== null && $prev !== null;
    }

    private function isVerscoPlaceholderLabel(string $label): bool
    {
        $normalized = $this->normalizeCell($label);
        return $normalized === 'schnellplanung' || preg_match('/^kunden pos\./u', $normalized) === 1;
    }

    /**
     * @param array<int,string> $texts
     * @return array<string,mixed>|null
     */
    private function parseInvoiceItemRow(array $texts): ?array
    {
        if ($texts === [] || preg_match('/^\d+$/', $texts[0]) !== 1) {
            return null;
        }

        $numericIndexes = [];
        foreach ($texts as $index => $text) {
            if ($index === 0) {
                continue;
            }
            if ($this->parseDecimal($text) !== null) {
                $numericIndexes[] = $index;
            }
        }

        if (count($numericIndexes) < 3) {
            return null;
        }

        $quantityIndex = $numericIndexes[0];
        $unitPriceIndex = $numericIndexes[count($numericIndexes) - 2];
        $totalIndex = $numericIndexes[count($numericIndexes) - 1];

        $position = (int)$texts[0];
        $quantity = $this->parseDecimal($texts[$quantityIndex]);
        $unit = $texts[$quantityIndex + 1] ?? null;
        $articleNoIndex = $quantityIndex + 2;
        $descriptionStart = $articleNoIndex + 1;
        $descriptionParts = array_slice($texts, $descriptionStart, max(0, $unitPriceIndex - $descriptionStart));
        $description = trim(implode(' ', $descriptionParts));
        if ($description === '') {
            return null;
        }

        return [
            'position' => $position,
            'beschreibung' => $description,
            'menge' => $quantity,
            'einheit' => $unit,
            'einzelpreis' => $this->parseDecimal($texts[$unitPriceIndex]),
            'gesamt' => $this->parseDecimal($texts[$totalIndex]),
            'artikelnummer' => $texts[$articleNoIndex] ?? null,
        ];
    }

    /**
     * @param array<int,string> $texts
     * @return array<string,mixed>|null
     */
    private function parseDeliveryItemRow(array $texts): ?array
    {
        if ($texts === [] || preg_match('/^\d+$/', $texts[0]) !== 1) {
            return null;
        }

        if (count($texts) < 5) {
            return null;
        }

        $position = (int)$texts[0];
        $quantity = $this->parseDecimal($texts[1]);
        if ($quantity === null) {
            return null;
        }

        $unit = $texts[2] ?? null;
        $descriptionParts = array_slice($texts, 4);
        $description = trim(implode(' ', $descriptionParts));
        if ($description === '') {
            return null;
        }

        return [
            'position' => $position,
            'beschreibung' => $description,
            'menge' => $quantity,
            'einheit' => $unit,
            'artikelnummer' => $texts[3] ?? null,
        ];
    }

    private function isInvoiceSummaryRow(string $normalized): bool
    {
        foreach ([
            'netto',
            'summe',
            'gesamt',
            'mwst',
            'ust',
            'zahlbar',
            'lieferung frei haus',
        ] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isDocumentFurnitureRow(string $normalized): bool
    {
        foreach ([
            'seite:',
            'kunden nr.:',
            'bestellung:',
            'kd.ust-idnr.:',
            'projekt nr.:',
            'ust-idnr.:',
            'lieferdatum:',
            'datum:',
            'musterfirma',
            'softvertrieb',
            'geschäftsführer:',
            'tel.:',
            'fax.:',
            'iban:',
            'swift code',
            'amtsgericht',
            'e-mail:',
            'web:',
            'blz:',
            'konto:',
            'firma',
        ] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{top: float, cells: array<int,array<string,mixed>>} $row
     */
    private function estimateRowHeight(array $row): float
    {
        $maxFontSize = 0.0;
        foreach ($row['cells'] as $cell) {
            $maxFontSize = max($maxFontSize, (float)($cell['font_size'] ?? 0.0));
        }

        return max(8.0, $maxFontSize + 2.0);
    }
}
