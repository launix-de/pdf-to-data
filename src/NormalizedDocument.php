<?php

declare(strict_types=1);

namespace Launix\PdfToData;

use Launix\PdfToData\Internal\XtractEngine;

final class NormalizedDocument
{
    /**
     * @param array<string,mixed> $meta
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $elements
     */
    public function __construct(
        private readonly array $meta,
        private readonly array $pages,
        private readonly array $elements,
        private readonly string $html
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function elements(): array
    {
        return $this->elements;
    }

    public function html(): string
    {
        return $this->html;
    }

    /**
     * Render a positioned element list back into absolute-positioned HTML.
     *
     * If an AABB (`x`, `y`, `w`, `h`) is supplied it acts both as crop window
     * and as the new local coordinate system for the rendered HTML.
     *
     * Without an AABB the smallest occurring left/top offset is subtracted
     * automatically so detail snippets can still be rendered in local
     * coordinates without caller-side normalization.
     *
     * @param array<int,array<string,mixed>>|null $elements
     * @param array{x: float|int, y: float|int, w: float|int, h: float|int}|null $aabb
     */
    public function renderHtml(?array $elements = null, ?array $aabb = null): string
    {
        return XtractEngine::renderElementsHtml($elements ?? $this->elements, $aabb);
    }

    /**
     * Render a positioned element list into an embeddable HTML fragment.
     *
     * @param array<int,array<string,mixed>>|null $elements
     * @param array{x: float|int, y: float|int, w: float|int, h: float|int}|null $aabb
     */
    public function renderHtmlFragment(?array $elements = null, ?array $aabb = null): string
    {
        return XtractEngine::renderElementsHtmlFragment($elements ?? $this->elements, $aabb);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'pages' => $this->pages,
            'items' => $this->elements,
            'html' => $this->html,
        ];
    }
}
