<?php

declare(strict_types=1);

namespace Launix\PdfToData\Contract;

use Launix\PdfToData\NormalizedDocument;

interface ExtractorEngine
{
    /**
     * Read the PDF into a raw positioned element list without removing page furniture.
     *
     * @param array<string,mixed> $options
     */
    public function extractRaw(string $pdfBytes, string $filename, array $options = []): NormalizedDocument;

    /**
     * Normalize one PDF into one continuous content stream with extracted elements.
     *
     * @param array<string,mixed> $options
     */
    public function extract(string $pdfBytes, string $filename, array $options = []): NormalizedDocument;
}
