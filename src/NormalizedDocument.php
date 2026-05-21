<?php

declare(strict_types=1);

namespace Launix\PdfToData;

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
