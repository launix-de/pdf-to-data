<?php

declare(strict_types=1);

namespace Launix\PdfToData;

final class SalesDocumentExtractor
{
    /**
     * Build a first reusable sales-document payload from normalized elements.
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
        ];
    }
}
