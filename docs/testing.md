# Testing Strategy

PdfToolkit now has a first-pass corpus and integration harness intended to go
beyond narrow unit tests.

## Current Layers

- Unit and focused subsystem tests in [tests](/Users/alexdrake/Sites/PdfToolbox/tests)
- Real-world fixture integration coverage in:
  - [tests/RealWorldFixtureIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/RealWorldFixtureIntegrationTest.php)
  - [tests/Integration/CorpusRoundTripIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/CorpusRoundTripIntegrationTest.php)
- Workflow smoke coverage in:
  - [tests/Integration/WorkflowSmokeIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/WorkflowSmokeIntegrationTest.php)
- Corpus save/load variant coverage in:
  - [tests/Integration/CorpusVariantIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/CorpusVariantIntegrationTest.php)
- Corrupted-input mutation coverage in:
  - [tests/Integration/CorruptedInputIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/CorruptedInputIntegrationTest.php)
- Rendered visual smoke coverage in:
  - [tests/Integration/RenderedWorkflowIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/RenderedWorkflowIntegrationTest.php)
- Stress workflow coverage in:
  - [tests/Integration/StressWorkflowIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/StressWorkflowIntegrationTest.php)
- Stress runner:
  - [scripts/run-stress.php](/Users/alexdrake/Sites/PdfToolbox/scripts/run-stress.php)
- CLI corpus runner:
  - [scripts/run-corpus.php](/Users/alexdrake/Sites/PdfToolbox/scripts/run-corpus.php)

## Corpus Goals

The corpus layer is meant to catch bugs that ordinary unit tests often miss:

- real-world parser compatibility issues
- imported-document round-trip regressions
- overlay/content-merging bugs
- form-fill workflow regressions
- cross-subsystem workflow bugs where generation, import, forms, SVG, and encryption interact
- save-option interaction bugs on real fixtures, especially compression and encryption
- controlled failure behavior for truncated, damaged, and partially malformed inputs
- rendered output regressions that structural PDF assertions might miss
- scaling issues in larger generated, paginated, and encrypted workflows
- ecosystem compatibility problems detected by external PDF tools

## Optional External Validators

When installed locally, the corpus runner and integration tests also validate
saved PDFs with:

- `qpdf --check`
- `pdfinfo`
- `pdftotext`

These checks are optional. The suite still runs when those tools are not
installed.

## Recommended Expansion Order

1. Add more redistributable real-world fixtures to the corpus.
2. Expand the workflow matrix for each fixture only when relevant.
3. Add rendered snapshot comparisons for high-value workflows.
4. Expand malformed/corrupted input fuzz and mutation fixtures.
5. Keep expanding performance/stress scenarios for larger documents and reusable metrics.

## Recent Corpus Win

During initial corpus rollout, the `f1099msc.pdf` form-fill workflow exposed a
real parser round-trip bug in regenerated AcroForm appearance output. That bug
has now been fixed and preserved as an explicit regression path in both:

- [tests/RealWorldFixtureIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/RealWorldFixtureIntegrationTest.php)
- [tests/Integration/CorpusRoundTripIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/CorpusRoundTripIntegrationTest.php)

## Current Shipped Corpus

The currently shipped real-world corpus includes:

- `examples/f1099msc.pdf`
- `examples/chubb_application_form.pdf`

## Running

```bash
vendor/bin/phpunit
vendor/bin/phpunit tests/Integration/CorpusRoundTripIntegrationTest.php
vendor/bin/phpunit tests/Integration/CorpusVariantIntegrationTest.php
vendor/bin/phpunit tests/Integration/WorkflowSmokeIntegrationTest.php
vendor/bin/phpunit tests/Integration/CorruptedInputIntegrationTest.php
vendor/bin/phpunit tests/Integration/RenderedWorkflowIntegrationTest.php
vendor/bin/phpunit tests/Integration/StressWorkflowIntegrationTest.php
php scripts/run-corpus.php
php scripts/run-corpus-variants.php
php scripts/run-stress.php
```
