# Release Checklist

This checklist is the final gate for `PdfToolkit` `v1.0.0`.

## Package And Docs

- Confirm [public-api.md](public-api.md) matches the intended supported stable API surface
- Confirm [compatibility.md](compatibility.md) matches the intended practical support boundary
- Confirm [roadmap.md](roadmap.md) shows no remaining active v1 work
- Review `README.md` quick-start and examples links
- Review [examples/README.md](../examples/README.md) and the runnable examples
- Review `composer.json` package metadata
- Review `LICENSE`
- Review `CHANGELOG.md`

## Validation

- Run the full PHPUnit suite
- Run the real-fixture integration test file
- Verify the known environment warnings are still only:
  - `libpng` interlace warning
  - fontconfig writable-cache warnings

## Manual Smoke Checks

- Generate a new PDF from scratch
- Import a PDF and overlay text
- Fill an imported AcroForm PDF
- Save an encrypted PDF and reopen it when the relevant runtime support is available

## Release Decision

- Confirm there are no active roadmap items left for v1
- Confirm any remaining work belongs to the post-v1 backlog
- Tag and publish `v1.0.0`
