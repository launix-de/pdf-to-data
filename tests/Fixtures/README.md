# Test Fixtures

This directory is the automatic PDF corpus for PHPUnit integration tests.

## Folder layout

- `tests/Fixtures/public/`
  Public, versioned fixtures and their expected values.
- `tests/Fixtures/private/`
  Private, unversioned fixtures for local development.

## Naming

For every PDF fixture, place one expected file next to it:

- `example.pdf`
- `example.expected.json`

The PHPUnit corpus test discovers every `*.pdf` below `tests/Fixtures/` automatically and loads the sibling `*.expected.json`.

## Expected JSON format

```json
{
  "normalized": {
    "pages_count": 2,
    "elements_count_min": 50,
    "type_counts": {
      "text": 20,
      "image_min": 1
    },
    "text_contains": ["Angebot", "Fenster"],
    "text_not_contains": ["Footer text"],
    "html_contains": ["#canvas"],
    "html_not_contains": ["Footer text"],
    "meta": {
      "page_count": 2
    }
  },
  "sales_document": {
    "text_contains": ["Angebot", "Fenster"],
    "image_count_min": 1
  }
}
```

Only the keys you care about need to be present.
