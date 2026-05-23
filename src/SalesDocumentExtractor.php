<?php

declare(strict_types=1);

namespace Launix\PdfToData;

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

        $quotationTable = $this->extractQuotationTable($textElements);

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
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $textElements
     * @return array<string,mixed>|null
     */
    private function extractQuotationTable(array $textElements): ?array
    {
        $rows = $this->clusterTextRows($textElements);
        $headerIndex = null;
        $positionX = null;
        $priceX = null;
        $quantityX = null;
        $totalX = null;

        foreach ($rows as $index => $row) {
            $labelMap = [];
            foreach ($row['cells'] as $cell) {
                $labelMap[$this->normalizeCell((string)$cell['text'])] = (float)$cell['left'];
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
                $left = (float)$cell['left'];
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
     * @param array<int,array<string,mixed>> $textElements
     * @return array<int,array{top: float, cells: array<int,array<string,mixed>>}>
     */
    private function clusterTextRows(array $textElements): array
    {
        usort($textElements, static function (array $left, array $right): int {
            $topCompare = ((float)$left['top']) <=> ((float)$right['top']);
            if ($topCompare !== 0) {
                return $topCompare;
            }
            return ((float)$left['left']) <=> ((float)$right['left']);
        });

        $rows = [];
        foreach ($textElements as $element) {
            $text = trim((string)($element['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $top = (float)($element['top'] ?? 0.0);
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
            usort($row['cells'], static fn(array $left, array $right): int => ((float)$left['left']) <=> ((float)$right['left']));
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
}
