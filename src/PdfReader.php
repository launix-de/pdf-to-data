<?php

declare(strict_types=1);

namespace Launix\PdfToData;

use InvalidArgumentException;
use Launix\PdfToData\Contract\ExtractorEngine;
use Launix\PdfToData\Internal\XtractEngine;

final class PdfReader
{
    private readonly ExtractorEngine $extractor;
    private readonly NormalizedDocument $rawDocument;
    private ?NormalizedDocument $normalized = null;
    private NormalizedDocument $currentDocument;

    public function __construct(
        private readonly string $pdfBytes,
        private readonly string $filename = 'document.pdf',
        private readonly ?ExtractorEngine $engine = null
    ) {
        if ($this->pdfBytes === '') {
            throw new InvalidArgumentException('PDF input must not be empty.');
        }

        $this->extractor = $this->engine ?? new XtractEngine();
        $this->rawDocument = $this->extractor->extractRaw($this->pdfBytes, $this->filename);
        $this->currentDocument = $this->rawDocument;
    }

    public static function fromFile(string $filename, ?ExtractorEngine $engine = null): self
    {
        if (!is_file($filename)) {
            throw new InvalidArgumentException(sprintf('PDF file not found: %s', $filename));
        }

        $bytes = file_get_contents($filename);
        if (!is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException(sprintf('Could not read PDF file: %s', $filename));
        }

        return new self($bytes, basename($filename), $engine);
    }

    public static function fromString(string $pdfBytes, string $filename = 'document.pdf', ?ExtractorEngine $engine = null): self
    {
        return new self($pdfBytes, $filename, $engine);
    }

    /**
     * @param resource $stream
     */
    public static function fromStream($stream, string $filename = 'document.pdf', ?ExtractorEngine $engine = null): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Expected a valid stream resource.');
        }

        $bytes = stream_get_contents($stream);
        if (!is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('Could not read PDF bytes from stream.');
        }

        return new self($bytes, $filename, $engine);
    }

    /**
     * Normalize the already loaded elements into one continuous stream without repeated page furniture.
     *
     * @param array<string,mixed> $options
     */
    public function removeFooters(array $options = []): NormalizedDocument
    {
        if ($this->normalized instanceof NormalizedDocument && $options === []) {
            $this->currentDocument = $this->normalized;
            return $this->normalized;
        }

        $normalized = $this->extractor->extract($this->pdfBytes, $this->filename, $options);
        $this->currentDocument = $normalized;

        if ($options === []) {
            $this->normalized = $normalized;
        }

        return $normalized;
    }

    /**
     * Return the currently loaded positioned elements.
     *
     * Before `removeFooters()` this is the raw per-page element list.
     * After `removeFooters()` this is the compacted one-page stream.
     *
     * @return array<int,array<string,mixed>>
     */
    public function extractElements(): array
    {
        return $this->currentDocument->elements();
    }

    /**
     * Build a higher-level sales-document payload from the current element list.
     *
     * @return array<string,mixed>
     */
    public function extractSalesDocument(): array
    {
        return (new SalesDocumentExtractor())->extract($this->currentDocument);
    }
}
