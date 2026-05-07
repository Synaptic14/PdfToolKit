# Real-World Fixture Corpus

This directory is reserved for future non-synthetic corpus fixtures used by
integration and differential testing.

Current shipped real-world coverage uses:

- [examples/f1099msc.pdf](/Users/alexdrake/Sites/PdfToolbox/examples/f1099msc.pdf)
- [examples/chubb_application_form.pdf](/Users/alexdrake/Sites/PdfToolbox/examples/chubb_application_form.pdf)

through the shared corpus fixture matrix in:

- [tests/Support/CorpusFixtures.php](/Users/alexdrake/Sites/PdfToolbox/tests/Support/CorpusFixtures.php)
- [tests/Integration/CorpusRoundTripIntegrationTest.php](/Users/alexdrake/Sites/PdfToolbox/tests/Integration/CorpusRoundTripIntegrationTest.php)
- [scripts/run-corpus.php](/Users/alexdrake/Sites/PdfToolbox/scripts/run-corpus.php)

When adding new fixtures:

1. Prefer redistributable PDFs and image assets.
2. Record the source and why the fixture is valuable.
3. Add the fixture to `CorpusFixtures::all()`.
4. Expand the expected workflow matrix only when that fixture really supports it.
