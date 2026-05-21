# pdf-to-data

`pdf-to-data` is a reusable PHP library that turns PDFs into a normalized, header/footer-cleaned HTML stream plus positioned elements that higher-level extractors can reuse.

## Goals

- Read PDFs from a file path, raw string, or PHP stream.
- Normalize a multi-page PDF into one continuous content stream.
- Remove repeated headers, repeated footers, and the whitespace they occupy.
- Extract positioned text, vector, and image elements.
- Build higher-level extractors, such as sales-document parsing, on top of those normalized elements.

## Public API

```php
use Launix\PdfToData\PdfReader;

$reader = PdfReader::fromFile('/path/to/document.pdf');

$normalized = $reader->removeFooters();
$elements = $reader->extractElements();
$salesDocument = $reader->extractSalesDocument();
```

### Input variants

```php
$reader = PdfReader::fromFile('/path/to/document.pdf');
$reader = PdfReader::fromString($pdfBytes, 'document.pdf');
$reader = PdfReader::fromStream($stream, 'document.pdf');
```

## What `xtract` is responsible for

The internal `xtract` engine is the phase-1 PDF normalizer.

Its job is deliberately limited:

1. Read the PDF and decode text, images, and vector-derived image assets.
2. Detect repeated headers and footers across pages.
3. Remove those repeated regions and collapse the remaining page content into one continuous coordinate stream.
4. Rebuild that continuous stream as absolute-positioned HTML and as structured JSON elements.

Higher-level interpretation, such as line-item detection or sales-document parsing, must happen on top of that normalized element stream.

## Tests

The repository intentionally ignores sensitive customer PDFs.

- Unit tests cover the public API without needing private documents.
- Integration tests use a generated synthetic PDF.
- Additional real-world fixtures can later be added as official non-sensitive examples.

Run:

```bash
composer install
composer test
```
