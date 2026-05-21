# Architecture

## Layers

### `PdfReader`

Small facade that owns the input PDF bytes and exposes the stable public API:

- `removeFooters()`
- `extractElements()`
- `extractSalesDocument()`

### `XtractCliEngine`

Adapter around the current `xtract` implementation. It executes the extraction engine in an isolated temp directory and returns a normalized document object.

This keeps the public library API clean while the extraction algorithm can still evolve rapidly.

### `xtract_legacy.php`

Current extraction engine. It already contains the real PDF heuristics and remains the place where extraction fidelity is improved.

Its responsibility is:

- decode PDF text, vector, and image content
- detect repeated page furniture
- output one continuous element stream and matching HTML

### `SalesDocumentExtractor`

Thin higher-level adapter that consumes `extractElements()` output. It must not bypass `xtract`.

## Why this split

The main risk is algorithm churn inside the extractor while downstream users need a stable API. The wrapper architecture keeps both concerns separate:

- extraction can change aggressively
- consumers keep a consistent object-oriented entry point
