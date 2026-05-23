<?php

declare(strict_types=1);

namespace Launix\PdfToData\Internal;

use Launix\PdfToData\Contract\ExtractorEngine;
use Launix\PdfToData\NormalizedDocument;

/**
 * Backward-compatible alias for older internal callers.
 *
 * The implementation now runs fully in-process via XtractEngine and no longer
 * starts a second PHP interpreter.
 */
final class XtractCliEngine implements ExtractorEngine
{
    private readonly XtractEngine $engine;

    public function __construct()
    {
        $this->engine = new XtractEngine();
    }

    public function extractRaw(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
    {
        return $this->engine->extractRaw($pdfBytes, $filename, $options);
    }

    public function extract(string $pdfBytes, string $filename, array $options = []): NormalizedDocument
    {
        return $this->engine->extract($pdfBytes, $filename, $options);
    }
}
