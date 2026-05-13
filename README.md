# PdfToolkit

`PdfToolkit` is a native PHP PDF toolkit for PHP 8.2+ focused on two jobs:

- generating PDFs dynamically with a modern API
- importing and modifying existing PDFs without relying on an external parser/import engine

This project was created completely with AI assistance through Codex.
The project is intentionally staged toward a usable v1 first, with a package structure that leaves room for deeper PDF feature support over time.

## Goals

- Native parser/import pipeline for PDF versions `1.0` through `1.8`
- Document generation with layout, drawing, text, images, forms, and templates
- Import and modification of existing PDFs, including page overlays and page-level transformations
- Preservation of advanced features when possible during import and save
- Unicode-ready text stack that can evolve toward shaping and font subsetting
- PHP `8.2+`

## Status

This repository currently contains the initial package scaffold, core architecture, and MVP-oriented APIs.

## Quick Start

## Installation

```bash
composer require pdftookit/pdftoolkit
```

The Composer package name is `pdftookit/pdftoolkit`, and the PHP namespace is `PdfToolkit\\...`.

Optional local capabilities:

- `ext-openssl` for AES-based encrypted PDF import/export workflows
- `ext-gd` for some raster fallback image decoding paths
- `magick` for trusted SVG raster fallback workflows when `PDFTOOLKIT_ENABLE_SVG_MAGICK=1`

Then load Composer autoloading:

```php
<?php

require __DIR__ . '/vendor/autoload.php';
```

Generate a PDF from scratch:

```php
<?php

use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;

$bytes = Pdf::new()
    ->metadata(title: 'Invoice 1001', author: 'PdfToolkit')
    ->addPage()
    ->text(new TextRun('Hello PDF', x: 72, y: 72, fontSize: 16, font: Pdf::font('Helvetica', 'bold')))
    ->endPage()
    ->build()
    ->save();
```

Import an existing PDF and overlay text on page 1:

```php
<?php

use PdfToolkit\Pdf;

$bytes = Pdf::load('/path/to/source.pdf')
    ->pages()
    ->page(1)
    ->overlayText('Approved', x: 72, y: 72, fontSize: 24)
    ->done()
    ->done()
    ->save();
```

Fill an imported AcroForm PDF:

```php
<?php

use PdfToolkit\Pdf;

$bytes = Pdf::load('/path/to/form.pdf')
    ->form()
    ->setText('customer_name', 'Grace Hopper')
    ->setCheckbox('accepted_terms', true)
    ->regenerateAppearances()
    ->done()
    ->save();
```

Coordinates in the public API use a top-origin model. `y = 0` means the top of the page.

## Examples

See [examples/README.md](examples/README.md) for the main runnable examples.

Recommended starting points:

- `examples/generate_hello_world.php`
- `examples/generate_hello_world_browser.php`
- `examples/import_add_text.php`
- `examples/import_add_text_browser.php`
- `examples/fill_form_browser.php`
- `examples/list_form_fields_browser.php`

## Release Docs

- Stable API surface: [docs/public-api.md](docs/public-api.md)
- Compatibility and limitations: [docs/compatibility.md](docs/compatibility.md)
- Roadmap: [docs/roadmap.md](docs/roadmap.md)
- Release checklist: [docs/release-checklist.md](docs/release-checklist.md)

Implemented in this first pass:

- package metadata and autoloading
- core document model
- fluent document builder entry point
- page, graphics, text, image, and form element definitions
- native parser foundation with typed PDF references, dictionaries, arrays, strings, and streams
- classic xref table and trailer parsing
- catalog and page tree resolution with inherited `MediaBox` support
- writer pipeline skeleton for exporting generated or imported documents
- importer wiring that preserves source page sizes in the in-memory document model
- imported page provenance with preserved page dictionaries, resource dictionaries, and raw content streams
- tolerant stream parsing that can fall back to `endstream` when declared lengths are inconsistent
- imported document rewrite that preserves original page content streams and appends overlay content
- xref stream parsing and compressed object stream loading for newer PDF files
- parsed content-stream operators exposed on imported pages for inspection and future editing
- imported content-stream rewriting through the page editor, including text replacement in parsed operators
- imported text replacement supports simple `Tj` strings and nested `TJ` text-showing arrays
- coordinate translation for common imported text and path operators such as `Tm`, `Td`, `m`, `l`, and `re`
- targeted imported text-position editing for `Tm`, `Td`, and `TD`, including direct text-matrix replacement
- targeted imported graphics-matrix editing for `cm`
- targeted imported operator rewriting for line width, stroke/fill colors, and font/XObject/ExtGState resource references
- generated text and graphics now support grayscale, RGB, and CMYK color operators
- imported graphics-state rewriting also supports line caps, line joins, miter limits, dash patterns, rendering intent, flatness, transparency/blend/overprint/flag controls, preserved `ExtGState` quality controls like `RI`, `FL`, and `SM`, preserved `ExtGState` line-style controls like `LW`, `LC`, `LJ`, `ML`, and `D`, and preserved name-based color-generation/transfer controls like `BG`, `BG2`, `UCR`, `UCR2`, `TR`, `TR2`, and `HT`
- imported text-state rewriting also supports font size, character spacing, word spacing, horizontal scaling, leading, text rise, and text rendering mode
- imported stroke/fill color rewriting now supports grayscale, RGB, and CMYK operators
- generated page content now compiles through the same operation-and-serializer model used by imported content
- base-font registry support for common PDF fonts with per-page font resource mapping
- standard-font metrics and public text measurement through `Pdf::measureText()`
- UTF-16BE hex serialization for non-ASCII content strings in generated and rewritten streams
- initial ToUnicode CMap generation for used single-byte base-font characters
- embedded custom TrueType font support for basic Latin generated text
- custom TrueType kerning-pair support for generated custom-font text, including composite Unicode output, through `TJ` spacing adjustments when the font exposes classic `kern` pairs or `GPOS` pair positioning
- initial GSUB single-substitution, alternate-substitution, multiple-substitution, and ligature shaping for generated custom TrueType/TTC text when the font exposes Unicode-mappable replacement glyphs, including chained single-substitution resolution through unmapped intermediates, Unicode alternate fallback when a direct single substitution is not displayable, first-match Unicode alternate selection, initial Unicode-mappable multiple-substitution expansion with original-source preservation both when it stays expanded and when later shaping collapses it again, iterative ligature resolution, single-substitution feature coverage for `ccmp`, `rlig`, `locl`, `rvrn`, `smcp`, `c2sc`, `case`, `titl`, `unic`, width variants like `fwid`, `hwid`, `twid`, `qwid`, `pwid`, `pnum`, and `tnum`, number/superior forms like `lnum`, `onum`, `sinf`, `subs`, `sups`, `ordn`, `numr`, `dnom`, and `zero`, and vertical or kana variants like `vert`, `vrt2`, `ruby`, `hkna`, and `vkna`, alternate-substitution feature coverage for `salt`, `aalt`, `nalt`, `hist`, `swsh`, `cswh`, `ornm`, `rand`, Japanese variant features like `jp78`, `jp83`, `jp90`, `jp04`, `hojo`, and `nlck`, and `ss01` through `ss20`, multiple-substitution feature coverage for `ccmp`, `locl`, `rvrn`, `calt`, `frac`, and `afrc`, and ligature coverage for `liga`, `rlig`, `clig`, `dlig`, `hlig`, `calt`, and `rclt`, with composite `ToUnicode` mapping preserved back to the original source-text sequence, including expanded multiple-substitution continuations, and automatic composite fallback when shaped source text differs from the displayed glyph
- composite Type0/CIDFont output for embedded TrueType generated Unicode text, including supplementary-plane characters, through sequential CID mapping with initial ligature-oriented shaping
- custom font loading now accepts both `.ttf` and `.ttc` sources, including explicit TTC face selection
- public font helpers through `Pdf::font()`, `Pdf::trueTypeFont()`, and `FontReference::trueType()`
- native custom TrueType/TTC text measurement through parsed font metrics and kerning pairs
- basic embedded TrueType font widths and descriptor metrics now come from parsed native font data rather than placeholder values
- embedded custom TrueType/TTC fonts now use parsed internal PostScript names for `/BaseFont` and descriptor naming
- embedded custom TrueType/TTC descriptor flags now derive from parsed fixed-pitch/italic/bold traits instead of a generic placeholder
- embedded custom TrueType/TTC descriptors now also populate parsed `XHeight` and a weight-based `StemV`
- embedded custom TrueType/TTC descriptors now also populate parsed `Leading`, `AvgWidth`, `MaxWidth`, and `MissingWidth`
- embedded custom TrueType/TTC output now performs real glyph-program subsetting with dense glyph remapping on both the simple embedded and composite CIDFont paths, rewrites the simple-path `cmap`, rewrites internal subset-font `name` tables to match emitted subset names, and respects `OS/2 fsType` no-subsetting restrictions
- simple embedded TrueType output now narrows `FirstChar`/`LastChar` and width arrays to the actually used single-byte character range
- composite embedded TrueType output now emits `/CIDSet` streams for the actually used CID set
- parsed `OS/2 fsType` embedding rights are now respected, and restricted-license fonts are rejected before embedding
- generated-page image embedding for JPEG and RGB/grayscale PNG files using PDF XObjects
- CMYK JPEG embedding now preserves `/DeviceCMYK` and adds Adobe-style inversion `/Decode` arrays when needed
- JPEGs with embedded ICC profiles now preserve those profiles through `/ICCBased` image colorspaces
- RGBA PNG embedding with soft-mask (`/SMask`) image XObjects
- grayscale+alpha PNG embedding with soft-mask (`/SMask`) image XObjects
- grayscale and RGB PNG `tRNS` transparency now map to native `/Mask` arrays
- native indexed PNG embedding with `/Indexed` colorspaces and palette-transparency soft masks
- low-bit-depth grayscale and indexed PNGs now embed natively without raster fallback
- low-bit grayscale and indexed PNG transparency now stays native too
- interlaced PNG embedding through raster fallback decoding
- WebP embedding through raster fallback decoding
- SVG embedding through opt-in ImageMagick raster fallback decoding for trusted input
- generated AcroForm text fields and checkboxes as widget annotations
- generated form-field flattening into normal page text and graphics
- imported AcroForm dictionary preservation, including copied dependent field objects when saving imported PDFs
- imported AcroForm text-field and checkbox value updates for direct fields by name
- imported direct text fields and checkboxes can be flattened into normal page content
- imported AcroForm editing supports hierarchical parent/child field names via qualified paths
- imported widgets can be reconnected to page `/Annots` arrays and `/P` page references
- imported direct text-field and checkbox widget appearances can be regenerated into `/AP` normal appearances
- page composition helpers for appending documents, extracting page ranges, and splitting documents into single-page documents
- imported page annotation preservation for existing `/Annots` arrays and dependent annotation objects
- generated text annotations with page `/Annots` integration
- generated URI link annotations with page `/Annots` integration
- generated internal page link annotations with `/Dest` targets
- fluent imported-page helpers for adding text notes, URI links, named-destination links, page boxes, shape overlays, redaction rectangles, clearing imported annotations, and selectively removing imported annotation subtypes
- imported annotation editing helpers for replacing preserved text-note contents, URI link targets, and internal page-link destinations in place
- generated outline/bookmark entries pointing to pages, including nested levels
- generated outline and internal link destination view options using `/XYZ`
- outline remapping and level normalization during document append and page extraction
- imported outline/bookmark tree preservation for catalog `/Outlines` object graphs
- imported trailer `/Info` metadata for title, author, subject, and comma-separated keywords
- imported page `/Rotate` preservation, including inherited page-tree rotation
- generated and imported page rotation editing
- imported page box preservation for inherited `CropBox`, `BleedBox`, `TrimBox`, and `ArtBox`
- generated and imported page box editing with validation helpers
- generated and imported page size editing
- raw-byte import through `Pdf::loadString()` and `Importer::loadString()`
- indirect stream `/Length` object support during parsing
- `/FlateDecode` page content-stream decoding for imported content inspection and rewriting
- `/Fl` filter abbreviation support for Flate-decoded streams
- `/ASCIIHexDecode` and `/AHx` stream decoding, including chained filters
- `/ASCII85Decode` and `/A85` stream decoding, including chained filters
- `/LZWDecode` and `/LZW` stream decoding with `/EarlyChange` support
- `/RunLengthDecode` and `/RL` stream decoding
- 8-bit TIFF and PNG predictor handling through stream `/DecodeParms`
- Standard Security encrypted-PDF import support, including revision 2/3/4 RC4 authentication, revision-4 `AESV2` import/decryption, revision-5 `AESV3` import/decryption with `/Perms` validation, default empty-password loading, decryption of imported strings/streams, revision-4 `/StrF` and `/StmF` `/Identity` handling, and revision-4/5 `/Crypt` stream-filter handling for RC4, `AESV2`, and `AESV3`, including explicit named `NoCrypt` and `Identity` stream overrides
- Imported document reports now expose structured encryption/security info when the source PDF was protected
- That security info includes the effective Standard Security permission mask from the source PDF
- That security info also preserves the effective string and stream crypt-filter names, such as legacy `Standard`, revision-4 `StdCF`, or `Identity`
- `ImportSecurityInfo` also exposes decoded permission helpers like `allowsPrint()` and `allowsCopy()`
- Imported security reports also record whether the document was opened through the user-password or owner-password authentication path
- `ImportSecurityInfo` also exposes whether that successful open used an explicit password or the empty-password path
- `ImportSecurityInfo` also exposes whether strings and streams were actually encrypted, including `Identity`-filter cases
- `ImportSecurityInfo` also exposes no-op filter helpers for both raw `/Identity` and named `/CFM /None` cases
- That includes aggregate helpers like `usesNoOpFilters()`, `usesNamedNoOpFilters()`, and `isFullyNoOpEncrypted()`
- `ImportSecurityInfo` also preserves the defined crypt filter names and exposes helpers like `usesCryptFilters()` and `usesCustomNamedCryptFilters()`
- It also exposes revision-aware default-filter helpers like `usesDefaultStandardCryptFilters()`, `usesDefaultRevision4CryptFilters()`, and `usesDefaultRevision5CryptFilters()`
- It also exposes the defined crypt-filter method map through `cryptFilters`, `definesCryptFilter(...)`, and `cryptFilterMethod(...)`
- It also exposes crypt-filter auth events through `cryptFilterAuthEvents`, `cryptFilterAuthEvent(...)`, and `usesDocOpenAuthEvent(...)`
- That same surface also exposes `usesEfOpenAuthEvent(...)` for embedded-file-oriented crypt-filter configurations
- It also exposes per-filter key lengths through `cryptFilterKeyLengthBits` and `cryptFilterKeyLengthBits(...)`
- It also exposes the optional embedded-file filter slot through `embeddedFileFilterName`, `embeddedFileMethod`, and `usesEmbeddedFileCryptFilter()`
- That embedded-file surface also includes no-op and metadata helpers like `usesNoOpEmbeddedFileFilter()`, `embeddedFileAuthEvent()`, and `embeddedFileKeyLengthBits()`
- It also exposes `usesEmbeddedFileEfOpenAuthEvent()` for embedded-file filters that authenticate on file open instead of document open
- It also includes default-filter helpers like `usesDefaultEmbeddedFileCryptFilter()`, `usesInheritedDefaultEmbeddedFileCryptFilter()`, `usesExplicitDefaultEmbeddedFileCryptFilter()`, `usesDefaultRevision4EmbeddedFileCryptFilter()`, and `usesDefaultRevision5EmbeddedFileCryptFilter()`
- It also includes comparison helpers like `embeddedFileFilterMatchesStringFilter()`, `embeddedFileFilterMatchesStreamFilter()`, and `hasDistinctEmbeddedFileCryptFilter()`
- It also exposes `embeddedFileAlgorithm()` and `embeddedFileAlgorithmSummary()` for a compact embedded-file encryption view
- It also categorizes the overall filter model with helpers like `usesLegacyStandardFilters()`, `usesDefaultRevision4CryptFilters()`, and `usesCustomCryptFilterConfiguration()`
- `ImportSecurityInfo` includes the effective key length in bits for the imported Standard Security configuration
- `ImportSecurityInfo` also exposes quick encryption-family helpers like `usesRc4()` and `usesAes()`
- `ImportSecurityInfo::algorithm()` returns a single high-level algorithm label like `RC4`, `AESV2`, or `AESV3`
- `ImportSecurityInfo::algorithmSummary()` can report `Mixed` when string and stream crypt methods differ
- `ImportSecurityInfo` also exposes simple `isPasswordProtected()` and `isEffectivelyEncrypted()` checks
- `ImportSecurityInfo` also exposes key-strength helpers like `isLegacy40Bit()` and `uses128BitKeys()`
- Standard Security writer-side export support for revision 2/3 RC4, revision 4 RC4/AESV2, and revision 5 AESV3 documents through `WriteOptions`, including optional explicit `/Crypt` stream filters and `/Identity` metadata overrides
- Revision-4 writer-side encryption can selectively leave strings or streams unencrypted via `/StrF /Identity` and `/StmF /Identity`
- When explicit `/Crypt` stream filters are enabled, writer-side no-op stream overrides now use a real named `/CFM /None` crypt filter for better revision-4 compatibility
- When explicit crypt filters are enabled, default no-op `/StrF` and `/StmF` selections can also use that named `/CFM /None` filter instead of only raw `/Identity`
- Revision-4 writer-side encryption can now also emit explicit embedded-file `/EFF` selections, including both inherited `StdCF` behavior and named no-op `NoCrypt` embedded-file filters
- Writer-side revision-4/5 encryption can also emit explicit embedded-file `EFOpen` crypt filters, using dedicated named embedded-file filter definitions when needed
- Revision-4-only writer options like explicit crypt filters, metadata encryption control, and selective string/stream encryption are now validated instead of being silently ignored on older revisions
- High-level `StandardPermissions` helpers for building Standard Security permission bitmasks without hand-authoring raw negative integers
- optional output stream compression through `WriteOptions(compressStreams: true)`
- imported catalog `/Names` name-tree preservation, including named destinations
- generated named destinations and `/Names` `/Dests` name-tree output
- generated link annotations that target named destinations
- imported outline destination rebinding for direct `/Dest` arrays and `/GoTo` `/D` arrays during save
- generated page labels through catalog `/PageLabels`
- generated viewer preferences through catalog `/ViewerPreferences`
- generated catalog `/OpenAction` entries for page and named-destination startup views
- generated catalog `/PageLayout` and `/PageMode` startup-view settings
- generated catalog `/Lang` document language support
- generated catalog `/MarkInfo` support
- generated catalog `/URI` base support
- high-level layout primitives for flow text, reusable text frames, content margins, stacked text blocks, panels, and paginated tables
- repeating page header/footer renderers with final page-number/page-count context
- multi-column text flow with derived column frames and continuation across pages
- panel-backed flow text helpers for single frames, content frames, reusable frame sets, and derived multi-column layouts
- stacked text blocks across reusable frames and multi-column layouts
- panelized block flow across reusable frames and multi-column layouts
- panel-backed table and record-table helpers
- grouped record-table helpers with customizable group headers and footers, including full-group and group-position formatter context
- panel-backed tables now redraw their containers across continuation pages
- panel-backed tables and record-driven tables can now also flow across reusable frames and derived multi-column layouts
- tables can now flow across reusable frames and derived multi-column layouts
- record-driven tables can also flow across reusable frames and derived multi-column layouts
- record-driven table helpers that build paginated tables directly from column definitions plus record arrays/objects, with column-filter hooks, record-sorting hooks, computed-column resolvers, group section hooks including grouped footer/subtotal rows, row-formatting hooks that can expand or skip records, formatter hooks that can return strings or full `TableCell` values, optional custom header cells, footer/summary row hooks, and empty-state row hooks
- table column definitions with default horizontal alignment, vertical alignment, padding, font, color, font size, line spacing, paragraph spacing, border, fill, and line width
- imported common `/OpenAction` destinations preserved as generated startup views
- imported catalog XMP `/Metadata` stream preservation
- inline catalog `/Metadata` dictionary/stream preservation
- generated catalog XMP `/Metadata` stream support from `DocumentMetadata`
- imported catalog `/PageLabels` preservation
- inline catalog `/PageLabels` preservation
- imported catalog `/ViewerPreferences` preservation
- inline catalog `/ViewerPreferences` preservation
- imported catalog `/PageLayout` and `/PageMode` preservation
- imported catalog `/Lang` preservation
- imported catalog `/MarkInfo` preservation
- imported catalog `/URI` base preservation
- imported catalog `/StructTreeRoot` preservation for tagged PDFs
- inline catalog `/StructTreeRoot` preservation
- imported catalog `/OutputIntents` preservation
- inline catalog `/OutputIntents` preservation
- representative import/save fixtures for declared PDF versions `1.3`, `1.4`, `1.7`, and `1.8`
- focused v1 roadmap documented in [docs/roadmap.md](docs/roadmap.md)

## Roadmap

The active roadmap is intentionally narrow now. The source of truth is [docs/roadmap.md](docs/roadmap.md).

The supported stable API surface for v1 is defined separately in [docs/public-api.md](docs/public-api.md).
The practical support boundary for v1 is documented in [docs/compatibility.md](docs/compatibility.md).

Done for v1:

- native parse/import for PDF `1.0` through `1.8`
- fresh PDF generation for the main document workflows
- import, modify, and save for existing PDFs
- practical font, shaping, subsetting, layout, forms, and encryption support for a usable v1

Remaining before `v1.0.0`:

- none

The public API audit/freeze step is complete. The supported v1 API boundary is defined in [docs/public-api.md](docs/public-api.md).
The docs/examples tightening step is complete through the quick-start section and [examples/README.md](examples/README.md).
The compatibility and limitations guide is complete in [docs/compatibility.md](docs/compatibility.md).
The focused stabilization pass is complete, including real-fixture integration coverage for `examples/f1099msc.pdf`.
The release-facing package polish step is complete through `composer.json` cleanup, `LICENSE`, `CHANGELOG.md`, and [docs/release-checklist.md](docs/release-checklist.md).

Post-v1 backlog:

- deeper shaping and complex-script behavior
- more obscure operator/resource and graphics-state fidelity work
- broader encryption coverage beyond the current Standard Security scope
- larger layout/rendering ambitions beyond the current primitives

## Proposed API direction

```php
<?php

use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;

$document = Pdf::new()
    ->metadata(title: 'Invoice 1001', author: 'PdfToolkit')
    ->addPage()
    ->text(new TextRun('Hello PDF', x: 72, y: 72, fontSize: 16, font: Pdf::font('Helvetica', 'bold')))
    ->line(72, 108, 180, 108)
    ->endPage()
    ->build();

$bytes = $document->save();
```

```php
<?php

use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;

$document = Pdf::new()
    ->addPage()
    ->text(new TextRun('Branded text', 72, 72, font: Pdf::trueTypeFont('/path/to/BrandSans.ttf')))
    ->text(new TextRun('Collection face', 72, 112, font: Pdf::trueTypeFont('/path/to/BrandSans.ttc', faceIndex: 1)))
    ->endPage()
    ->build();
```

```php
<?php

use PdfToolkit\Pdf;

$bytes = Pdf::load('/path/to/source.pdf')
    ->pages()
    ->page(1)
    ->overlayText('Approved', x: 72, y: 72, fontSize: 24)
    ->done()
    ->done()
    ->save('/path/to/output.pdf');
```

```php
<?php

use PdfToolkit\Pdf;

Pdf::load('/path/to/form.pdf')
    ->form()
    ->setText('customer_name', 'Grace Hopper')
    ->setCheckbox('accepted_terms', true)
    ->done()
    ->save('/path/to/filled.pdf');
```

```php
<?php

use PdfToolkit\Pdf;
use PdfToolkit\Writer\WriteOptions;

$imported = Pdf::loadString($pdfBytes);
$bytes = $imported->save(options: new WriteOptions(compressStreams: true));
```

## Architecture

- `src/Core`: document aggregates, metadata, page model, exceptions
- `src/Graphics`: drawing primitives, colors, geometry, style values
- `src/Text`: text runs, font requests, shaping placeholders
- `src/Layout`: high-level flow layout, panels, frames, and tables
- `src/Image`: image descriptors and placement values
- `src/Forms`: form field model and import/edit hooks
- `src/Parser`: native PDF reading pipeline
- `src/Import`: document import and modification orchestration
- `src/Writer`: serialization pipeline for output PDFs

## Roadmap

### Milestone 1

- tokenizer and low-level value parser
- classic trailer/xref parser
- indirect object loader
- basic catalog/pages parser
- document writer for newly created PDFs
- absolute positioning primitives
- text, lines, rectangles, images

### Milestone 2

- existing PDF import for standard xref tables
- overlay/stamping support
- page extraction and merge/split helpers
- metadata, outlines, annotations preservation where possible

### Milestone 3

- xref streams and object streams
- form import preservation and editing
- content stream parsing and rewriting
- Unicode shaping pipeline
- SVG and richer layout support

### Milestone 4

- advanced feature preservation across more edge cases
- performance and memory tuning
- deeper spec coverage and compatibility hardening

## License

MIT
