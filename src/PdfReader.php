<?php

declare(strict_types=1);

namespace Launix\PdfToData;

use InvalidArgumentException;
use Launix\PdfToData\Contract\ExtractorEngine;
use Launix\PdfToData\Internal\XtractCliEngine;

final class PdfReader
{
    private ?NormalizedDocument $normalized = null;

    public function __construct(
        private readonly string $pdfBytes,
        private readonly string $filename = 'document.pdf',
        private readonly ?ExtractorEngine $engine = null
    ) {
        if ($this->pdfBytes === '') {
            throw new InvalidArgumentException('PDF input must not be empty.');
        }
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
     * Normalize the PDF into one continuous stream without repeated page furniture.
     *
     * @param array<string,mixed> $options
     */
    public function removeFooters(array $options = []): NormalizedDocument
    {
        if ($this->normalized instanceof NormalizedDocument && $options === []) {
            return $this->normalized;
        }

        $engine = $this->engine ?? new XtractCliEngine();
        $normalized = $engine->extract($this->pdfBytes, $this->filename, $options);

        if ($options === []) {
            $this->normalized = $normalized;
        }

        return $normalized;
    }

    /**
     * Return all positioned elements of the normalized document.
     *
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    public function extractElements(array $options = []): array
    {
        return $this->removeFooters($options)->elements();
    }

    /**
     * Build a higher-level sales-document payload from normalized elements.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function extractSalesDocument(array $options = []): array
    {
        return (new SalesDocumentExtractor())->extract($this->removeFooters($options));
    }
}
