# pdf-to-data

`pdf-to-data` is a reusable PHP library that first reads PDFs into a positioned element list and can then normalize that list into a header/footer-cleaned HTML stream that higher-level extractors can reuse.

## Goals

- Read PDFs from a file path, raw string, or PHP stream.
- Load a multi-page PDF into a raw per-page element list.
- Normalize that element list into one continuous content stream.
- Remove repeated headers, repeated footers, and the whitespace they occupy.
- Extract positioned text, vector, and image elements.
- Build higher-level extractors, such as sales-document parsing, on top of those normalized elements.

## Public API

```php
use Launix\PdfToData\PdfReader;

$reader = PdfReader::fromFile('/path/to/document.pdf');

$rawElements = $reader->extractElements();
$normalized = $reader->removeFooters();
$elements = $reader->extractElements();
$salesDocument = $reader->extractSalesDocument();
```

`PdfReader` is stateful by design:

- the constructor immediately reads the PDF into a raw element list
- `extractElements()` is always just a getter for the current in-memory list
- `removeFooters()` transforms that current list into the compacted one-page stream

### Input variants

```php
$reader = PdfReader::fromFile('/path/to/document.pdf');
$reader = PdfReader::fromString($pdfBytes, 'document.pdf');
$reader = PdfReader::fromStream($stream, 'document.pdf');
```

## What `xtract` is responsible for

The internal `xtract` engine is the phase-1 PDF normalizer.

Its job is deliberately limited:

1. Read the PDF and decode text, images, and vector-derived image assets into positioned elements.
2. Optionally detect repeated headers and footers across pages.
3. Optionally remove those repeated regions and collapse the remaining page content into one continuous coordinate stream.
4. Rebuild either representation as absolute-positioned HTML and as structured JSON elements.

Higher-level interpretation, such as line-item detection or sales-document parsing, must happen on top of that normalized element stream.

## Tests

The repository intentionally ignores sensitive customer PDFs.

- PHPUnit covers the public API directly.
- Synthetic multi-page PDFs cover real `xtract` behavior without needing customer documents.
- Corpus tests discover every `*.pdf` below `tests/Fixtures/` automatically and load sibling `*.expected.json` files.
- Private local fixtures belong in `tests/Fixtures/private/` and stay unversioned.
- Future official examples can be committed in `tests/Fixtures/public/`.

Run:

```bash
composer install
composer test
```
