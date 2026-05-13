# Architecture Notes

Project naming for v1:

- Composer package: `pdftookit/pdftoolkit`
- PHP namespace: `PdfToolkit\\...`

## Design priorities

Ordered from highest to lowest for this project:

1. Fastest path to a usable v1
2. Performance and memory behavior
3. API elegance
4. Spec correctness

That ordering affects the initial implementation strategy:

- create a narrow but extendable public API first
- keep parser and writer internals split so they can mature independently
- preserve source PDF structures where possible rather than normalize everything immediately
- allow graceful warnings for unsupported advanced constructs during early milestones

## Layering

### Public API

- `PdfToolkit\Pdf`
- `PdfToolkit\Core\DocumentBuilder`
- import editing entry points under `PdfToolkit\Import`
- file-path and raw-byte import entry points through `Pdf::load()` and `Pdf::loadString()`

### Canonical in-memory model

- `Document`
- `Page`
- text, graphics, images, forms, metadata

This model is intentionally simpler than the full PDF object graph. The parser layer should eventually hold lower-level structures alongside this model so import fidelity can improve without leaking complexity into the public API.

Generated output is now compiled into content operations before serialization, which brings generated and imported page output onto the same core stream model.

The public builder also now has an initial higher-level layout layer for flowing text, reusable page frames, derived multi-column frames, repeating page header/footer renderers with final page-number/page-count context, panel-backed flow-text helpers for single and multi-frame layouts, stacked text blocks including multi-frame and multi-column variants, panel containers including multi-frame and multi-column block-flow variants, table flow across reusable frames and derived multi-column layouts, panel-backed table helpers with continuation-page redraw support plus matching reusable-frame and derived multi-column variants, paginated tables with spans plus per-column alignment, padding, font, text-metric, color, and visual-style defaults, and a record-driven table helper that maps arrays/objects into those tables with column-filter hooks, record-sorting hooks, computed-column resolvers, group section hooks including grouped footer/subtotal rows, row-formatting hooks that can expand or skip records, formatter hooks that can emit either plain strings or full `TableCell` objects, optional custom header cells, footer/summary row hooks, empty-state row hooks, and matching multi-frame / multi-column table-flow helpers.

The writer also has an initial font subsystem for standard PDF base fonts, including registry-based family/style resolution and per-page font resources.

Text measurement is available through a standard-font metrics layer plus native custom TrueType/TTC measurement using parsed font advances, classic `kern` pairs, `GPOS` pair positioning, and initial GSUB single-substitution, alternate-substitution, multiple-substitution, plus ligature shaping when the font exposes Unicode-mappable replacement glyphs. Custom font loading now accepts both standalone `.ttf` files and `.ttc` TrueType Collections, including explicit collection face selection by index. Generated custom TrueType output now has two paths: a simple single-byte TrueType path for basic Latin and a composite Type0/CIDFont path that assigns sequential CIDs for Unicode text, including supplementary-plane characters when the font provides glyph coverage. The simple embedded TrueType path now also derives widths, descriptor metrics, descriptor flags, `XHeight`, `Leading`, `AvgWidth`, `MaxWidth`, `MissingWidth`, and a weight-based `StemV` from parsed native font data instead of placeholder values, narrows `FirstChar`/`LastChar` plus width arrays to the actually used single-byte character range, uses parsed internal PostScript names from the font `name` table for `/BaseFont` and descriptor naming, performs real glyph-program subsetting with dense glyph remapping and subset-name tagging, rewrites the embedded simple-path `cmap` to match that remapping, rewrites internal subset-font `name` table PostScript names to match emitted subset names, and respects parsed `OS/2 fsType` embedding-rights and no-subsetting restrictions. The composite Unicode path now also emits `/CIDSet` streams for the actually used CID set, performs dense glyph remapping into compact embedded glyph ranges, rewrites composite-glyph component references to match that remapping, uses the same internal subset-font naming rewrite, and now receives the same single-substitution, alternate-substitution, multiple-substitution, and ligature-oriented shaping pass when Unicode-mappable GSUB substitutions are available, including chained single-substitution resolution through unmapped intermediates, Unicode alternate fallback when a direct single substitution is not displayable, first-match Unicode alternate selection, initial Unicode-mappable multiple-substitution expansion, preserved original-source carry-through both when that expansion stays expanded and when later shaping collapses it again, iterative ligature resolution, single-substitution feature coverage for `ccmp`, `rlig`, `locl`, `rvrn`, `smcp`, `c2sc`, `case`, `titl`, `unic`, width variants like `fwid`, `hwid`, `twid`, `qwid`, `pwid`, `pnum`, and `tnum`, number/superior forms like `lnum`, `onum`, `sinf`, `subs`, `sups`, `ordn`, `numr`, `dnom`, and `zero`, and vertical or kana variants like `vert`, `vrt2`, `ruby`, `hkna`, and `vkna`, alternate-substitution feature coverage for `salt`, `aalt`, `nalt`, `hist`, `swsh`, `cswh`, `ornm`, `rand`, Japanese variant features like `jp78`, `jp83`, `jp90`, `jp04`, `hojo`, and `nlck`, and `ss01` through `ss20`, multiple-substitution feature coverage for `ccmp`, `locl`, `rvrn`, `calt`, `frac`, and `afrc`, and ligature feature coverage for `liga`, `rlig`, `clig`, `dlig`, `hlig`, `calt`, and `rclt`. That composite shaping path also preserves `ToUnicode` extraction fidelity by mapping shaped replacement glyphs back to their original source-text sequences, including expanded multiple-substitution continuations that map to empty trailing segments, and the writer now forces that composite route whenever shaped source text differs from the displayed glyph so extraction fidelity is not lost through an accidental single-byte fallback. Generated custom TrueType text also applies both classic `kern`-table kerning and parsed `GPOS` pair-positioning adjustments through `TJ` arrays when the font exposes them, including the composite Unicode path. Advanced shaping is now the main remaining font-system frontier.

Content-stream string serialization now emits ASCII as escaped literal strings and non-ASCII as UTF-16BE hex strings. This prevents data corruption during generation and imported stream rewrites, but it is not a substitute for full font embedding, shaping, or ToUnicode mapping.

Generated base-font resources also receive initial ToUnicode CMaps for used single-byte characters. This improves extraction for current base-font output, while full composite fonts and embedded font Unicode maps remain future work.

### Parser

Planned responsibilities:

- header/version handling
- tokenizer
- indirect object parser
- xref table parser
- xref stream parser
- object stream loader
- page tree resolution
- resource inheritance
- content stream parser

Current implementation:

- header/version validation for PDF `1.0` through `1.8`
- trailer `/Info` metadata extraction for common document properties
- low-level parser for names, arrays, dictionaries, literal strings, hex strings, numbers, booleans, nulls, and indirect references
- classic xref table and trailer parsing
- xref stream parsing
- indirect object loading by xref offset
- compressed object loading from `/ObjStm`
- page tree walking with inherited `MediaBox`
- inherited page `/Rotate` handling
- generated and imported page rotation editing
- inherited page box handling for `CropBox`, `BleedBox`, `TrimBox`, and `ArtBox`
- generated and imported page box editing with validation helpers
- generated and imported page size editing
- raw content stream preservation at the parsed-page level
- direct and indirect stream `/Length` handling
- `/FlateDecode` page content-stream decoding for imported operator parsing and rewriting
- `/Fl` filter abbreviation support for Flate-decoded streams
- `/ASCIIHexDecode` and `/AHx` stream decoding with chained filter support
- `/ASCII85Decode` and `/A85` stream decoding with chained filter support
- `/LZWDecode` and `/LZW` stream decoding with `/EarlyChange` support
- `/RunLengthDecode` and `/RL` stream decoding
- 8-bit TIFF and PNG predictor handling through stream `/DecodeParms`
- Standard Security encrypted-PDF import support, including revision 2/3/4 RC4 user-owner authentication, revision-4 `AESV2` import/decryption, revision-5 `AESV3` import/decryption plus `/Perms` validation, string/stream decryption during parsing, revision-4/5 `/StrF` and `/StmF` `/Identity` handling, and revision-4/5 crypt-filter handling including explicit `/Crypt` stream filters for RC4, `AESV2`, and `AESV3` plus explicit named `NoCrypt` and `Identity` stream overrides, and default empty-password loading
- Imported security reports preserve both the resolved encryption methods and the original string/stream crypt-filter names, including legacy `Standard`, revision-4 `StdCF`, and `Identity`
- Imported document reports expose structured encryption/security metadata for successfully loaded protected PDFs
- That report surface includes the source permission mask alongside revision and string/stream encryption methods
- It also exposes helpers for distinguishing raw `/Identity` filters from broader no-op filter cases such as named `/CFM /None`
- That helper surface also includes aggregate no-op checks so callers can tell when one side or both sides of the document are effectively left unencrypted
- Imported security reports also preserve the defined crypt filter names so callers can distinguish legacy protection, plain `StdCF`, and custom named filter configurations
- That same surface now also distinguishes generic default `StdCF` setups from revision-specific revision-4 and revision-5 defaults
- That same report surface also preserves the crypt-filter name-to-method map so callers can inspect what each named filter actually does
- It also preserves per-filter auth events so callers can inspect how each named crypt filter is meant to be applied
- That auth-event helper surface now includes both `DocOpen` and `EFOpen` convenience checks
- It also preserves per-filter key lengths so callers can inspect stronger revision-4 crypt-filter definitions more completely
- It also preserves the optional embedded-file crypt-filter selection so the imported security report covers the full top-level revision-4 filter set
- That embedded-file reporting also includes convenience helpers for no-op detection, default-filter detection, revision-aware default-filter detection, plus auth-event and key-length lookup of the selected embedded-file filter
- It also includes comparison helpers so callers can tell whether embedded files follow the same top-level filter as strings/streams or use a distinct crypt-filter selection
- It also includes a compact embedded-file algorithm summary so callers can report embedded-file protection without manually inspecting raw filter slots
- That same report surface also categorizes legacy Standard filters, default revision-4 `StdCF` setups, and broader custom crypt-filter configurations directly
- The import-security surface also decodes that mask into explicit capability helpers for common caller checks
- The same report surface records whether authentication succeeded through the user or owner password flow
- It also records whether the successful open used an explicit password or the empty-password path
- That same surface also reports whether strings and streams were actually encrypted versus left plaintext through `Identity` filters
- The report also exposes the effective key length in bits so callers can distinguish legacy 40-bit protection from 128-bit variants
- It also exposes quick encryption-family helpers so callers can distinguish RC4-based and AES-based protection without interpreting low-level method strings
- Embedded-file auth-event helpers also distinguish `DocOpen` from `EFOpen` for `/EFF` configurations
- It also exposes a single high-level algorithm label for common caller/reporting paths, including `AESV3`
- It also exposes a higher-level mixed-algorithm summary so callers can spot string/stream method divergence without comparing raw fields manually
- It also exposes simple high-level checks for “password protected” and “effectively encrypted” caller flows
- It also exposes key-strength helpers so callers can distinguish legacy 40-bit protection from 128-bit protection without manual threshold checks
- Standard Security writer-side export support for revision 2/3 RC4, revision 4 RC4/AESV2, and revision 5 AESV3 documents, including optional explicit `/Crypt` stream filters and `/Identity` metadata overrides
- Revision-4 writer-side encryption can also emit `/Identity` string or stream filters to leave selected object classes unencrypted while keeping the rest of the document protected
- When explicit `/Crypt` stream filters are enabled, unencrypted stream overrides can also be emitted through a named `/CFM /None` crypt filter instead of only raw `/Identity`
- That named no-op filter path can also be used for default revision-4 `/StrF` and `/StmF` selections when explicit crypt filters are enabled
- Revision-4 writer-side encryption can now also emit explicit `/EFF` embedded-file filter selections, including named no-op embedded-file filters
- Writer-side revision-4/5 encryption can also emit explicit embedded-file `EFOpen` crypt filters through dedicated named embedded-file filter definitions when needed
- Revision-4-only writer encryption controls are validated eagerly so older revision exports do not silently ignore stronger caller intent
- High-level Standard Security permission helpers so encrypted writer output can use validated revision-aware permission bitmasks
- generic content-stream operator parsing for imported page inspection
- grouped record-table layout hooks support customizable group headers and footers, including callbacks that can inspect the full current group and its position within the grouped sequence

### Import pipeline

The import layer should map parsed low-level objects into one of two modes:

- preservation mode for objects/features we can keep without rewriting deeply
- editable mode for structures we know how to transform safely

Current implementation keeps page-level provenance alongside the high-level `Page` model:

- imported document metadata for title, author, subject, and keywords
- source page object number
- imported page rotation
- generated and imported page rotation editing
- imported page boxes
- resolved page dictionary
- resolved inherited resources dictionary
- raw page content streams
- parsed operations for each preserved content stream
- dependent imported resource objects needed for rewrite
- dependent imported annotation objects referenced from page `/Annots`
- imported AcroForm source object and dependent form objects when the source catalog references an indirect `/AcroForm`
- imported outline source object and dependent bookmark objects when the source catalog references indirect `/Outlines`
- imported catalog name-tree source object and dependent objects when the source catalog references indirect `/Names`
- imported catalog XMP metadata source stream and dependent objects when the source catalog references indirect `/Metadata`
- imported catalog page-label source object and dependent objects when the source catalog references indirect `/PageLabels`
- imported catalog viewer-preferences source object and dependent objects when the source catalog references indirect `/ViewerPreferences`
- imported catalog scalar startup-view settings for `/PageLayout` and `/PageMode`
- imported common `/OpenAction` page and named-destination startup views mapped onto the high-level document model
- imported catalog scalar `/Lang` document language setting
- imported catalog `/MarkInfo` tagging/accessibility flags
- imported catalog scalar `/URI` base setting
- imported catalog `/StructTreeRoot` preservation for tagged PDFs
- imported catalog `/OutputIntents` preservation for color-managed PDFs
- warnings gathered during parsing

Current editing support:

- imported page operator inspection
- text replacement across parsed imported content-stream operands, including nested `TJ` arrays
- translation of common coordinate-bearing operators across imported text and path content
- targeted imported text-position rewriting for `Tm`, `Td`, and `TD`, including direct text-matrix replacement
- targeted imported graphics-matrix rewriting for `cm`
- content-stream reserialization back into saved output
- direct imported AcroForm text-field and checkbox value updates by field name
- document-level append, extract, and split helpers that work with generated and imported pages
- outline-aware append and extraction that remap generated bookmark page numbers and normalize extracted levels
- imported-page editor helpers for text annotations, URI links, named-destination links, page boxes, shape overlays, redaction rectangles, and clearing imported annotations

### Writer

The writer should support:

- fresh document serialization
- imported document rewrite
- incremental-save strategy later, if worth the complexity

Current implementation supports:

- fresh document serialization
- imported page rewrite using preserved content streams
- copied imported resource objects with remapped indirect references
- appended overlay content on imported pages
- generated page compilation through shared content operations and serializer logic
- optional Flate compression for generated and imported unfiltered content streams
- standard base-font registry and per-page font resource mapping for generated text and imported overlays
- standard-font width metrics and text measurement
- native custom TrueType/TTC text measurement from parsed font metrics, classic `kern`, and `GPOS` pair positioning
- initial GSUB single-substitution, alternate-substitution, multiple-substitution, and ligature shaping for generated/measured custom TrueType/TTC text when replacement glyphs have Unicode mappings, including chained single-substitution resolution, first-match Unicode alternate selection, initial Unicode-mappable multiple-substitution expansion, iterative ligature resolution, single-substitution feature coverage for `ccmp`, `rlig`, `locl`, `rvrn`, `smcp`, `c2sc`, `case`, `titl`, `unic`, `fwid`, `hwid`, `twid`, `qwid`, `pwid`, `pnum`, `tnum`, `lnum`, `onum`, `sinf`, `subs`, `sups`, `ordn`, `numr`, `dnom`, `zero`, `vert`, `vrt2`, `ruby`, `hkna`, and `vkna`, alternate-substitution feature coverage for `salt`, `aalt`, `nalt`, `hist`, `swsh`, `cswh`, `ornm`, `rand`, `jp78`, `jp83`, `jp90`, `jp04`, `hojo`, `nlck`, and `ss01` through `ss20`, multiple-substitution feature coverage for `ccmp`, `locl`, `rvrn`, `calt`, `frac`, and `afrc`, and ligature coverage for `liga`, `rlig`, `clig`, `dlig`, `hlig`, `calt`, and `rclt`, with composite `ToUnicode` preservation of the original source-text sequence
- native basic embedded TrueType widths and descriptor metrics from parsed font data
- parsed internal PostScript-name use for embedded TrueType/TTC `/BaseFont` and descriptor naming
- parsed fixed-pitch/italic/bold trait use for embedded TrueType/TTC descriptor flags
- parsed `XHeight` and weight-based `StemV` use for embedded TrueType/TTC descriptors
- parsed `Leading`, `AvgWidth`, `MaxWidth`, and `MissingWidth` use for embedded TrueType/TTC descriptors
- real glyph-program subsetting with dense glyph remapping for both simple and composite embedded TrueType/TTC output, including simple-path `cmap` rewriting, subset-name tagging, and internal subset-font `name` table rewriting
- used-range `FirstChar`/`LastChar` and width-array narrowing for simple embedded TrueType output
- `/CIDSet` generation for used CIDs in composite embedded TrueType output
- parsed `OS/2 fsType` rights handling and restricted-embedding rejection for custom TrueType/TTC fonts
- safe ASCII literal and non-ASCII UTF-16BE text-string serialization
- initial ToUnicode CMap generation for used single-byte base-font characters
- embedded custom TrueType font support for basic Latin generated text, including `FontFile2`, `FontDescriptor`, widths, and facade helpers
- generated TrueType kerning support through parsed classic `kern` tables, parsed `GPOS` pair positioning, and emitted `TJ` spacing adjustments
- composite Type0/CIDFont embedded TrueType output for generated Unicode text, with parsed `cmap` glyph mapping, sequential CID assignment, encoded text operands, supplementary-plane support, `CIDToGIDMap` generation, and initial ligature-oriented shaping
- TrueType Collection (`.ttc`) parsing support for custom font loading, metrics, embedding workflows, and explicit face selection through extracted standalone sfnt programs
- generated image XObjects for JPEG and RGB/grayscale PNG files
- CMYK JPEG embedding with Adobe APP14 detection and inversion `/Decode` arrays when needed
- JPEG ICC profile preservation through emitted `/ICCBased` image colorspaces
- generated text and graphics color compilation now supports grayscale, RGB, and CMYK PDF operators
- RGBA PNG soft-mask image generation
- grayscale+alpha PNG soft-mask image generation
- native grayscale and RGB PNG `tRNS` transparency handling through `/Mask` arrays
- native indexed PNG embedding with `/Indexed` colorspaces and palette-transparency soft masks
- native low-bit-depth grayscale and indexed PNG embedding for simple 1/2/4-bit assets
- native low-bit grayscale and indexed PNG transparency preservation
- interlaced PNG raster-fallback embedding
- WebP raster-fallback embedding
- SVG raster-fallback embedding through opt-in ImageMagick execution for trusted input
- generated text annotations with page annotation arrays
- generated URI link annotations with page annotation arrays
- generated internal page links and configurable `/XYZ` destination arrays
- generated AcroForm text fields and checkboxes with widget annotations
- generated form-field flattening into normal page text and graphics
- generated outline trees with page destinations and nested levels
- generated named destinations with `/Names` `/Dests` name-tree output
- generated link annotations that target named destinations
- generated page labels with `/PageLabels` number-tree output
- generated viewer preferences with `/ViewerPreferences` catalog output
- generated catalog `/OpenAction` entries for page and named-destination startup views
- generated catalog `/PageLayout` and `/PageMode` startup-view settings
- generated catalog XMP `/Metadata` stream output from high-level document metadata
- generated catalog `/Lang` document language support
- generated catalog `/MarkInfo` support
- generated catalog `/URI` base support
- imported AcroForm preservation by copying the source form object graph and remapping indirect references
- imported AcroForm value updates for direct fields before copied form objects are written
- imported direct AcroForm text-field and checkbox flattening into normal page content
- imported AcroForm field lookup supports hierarchical qualified names through `/Parent` chains
- imported widget-page reconnection can restore page `/Annots` membership and widget `/P` references
- imported direct text-field and checkbox appearance regeneration can emit simple widget `/AP` normal appearances
- imported page `/Annots` preservation with remapped dependent annotation references
- imported catalog `/Outlines` preservation with copied outline object graphs
- imported catalog `/Names` preservation with copied name-tree object graphs
- imported catalog XMP `/Metadata` preservation with copied stream object graphs
- inline catalog `/Metadata` preservation by lifting direct dictionary/stream values into saved indirect objects
- imported catalog `/PageLabels` preservation with copied number-tree object graphs
- inline catalog `/PageLabels` preservation by lifting direct catalog values into saved indirect objects
- imported catalog `/ViewerPreferences` preservation with copied preference object graphs
- inline catalog `/ViewerPreferences` preservation by lifting direct catalog values into saved indirect objects
- imported catalog `/PageLayout` and `/PageMode` preservation
- imported common `/OpenAction` destinations preserved as generated startup views
- imported outline destination rebinding for direct `/Dest` arrays and `/GoTo` `/D` arrays
- imported catalog `/Lang` preservation
- imported catalog `/MarkInfo` preservation
- imported catalog `/URI` base preservation
- imported catalog `/StructTreeRoot` preservation
- inline catalog `/StructTreeRoot` preservation by lifting direct catalog values into saved indirect objects
- imported catalog `/OutputIntents` preservation
- inline catalog `/OutputIntents` preservation by lifting direct catalog values into saved indirect objects
- targeted imported content-stream rewriting for line width, grayscale/RGB/CMYK stroke-fill colors, and resource references
- targeted imported graphics-state rewriting for line caps, line joins, miter limits, dash patterns, rendering intent, flatness, transparency/blend/overprint/flag controls, preserved `ExtGState` quality controls like `RI`, `FL`, and `SM`, preserved `ExtGState` line-style controls like `LW`, `LC`, `LJ`, `ML`, and `D`, and preserved name-based color-generation/transfer controls like `BG`, `BG2`, `UCR`, `UCR2`, `TR`, `TR2`, and `HT`
- targeted imported text-state rewriting for font size, character spacing, word spacing, horizontal scaling, leading, text rise, and text rendering mode
- selective imported annotation removal by subtype with dependent-object pruning
- imported annotation editing for preserved text-note contents, URI link actions, and internal page-link destinations
- representative integration fixtures covering declared PDF versions `1.3`, `1.4`, `1.7`, and `1.8`

## Current V1 Roadmap

The project originally treated feature growth and fidelity hardening as the same roadmap. That made the remaining work effectively unbounded. The active v1 roadmap is now intentionally narrower and release-focused.

Done for v1:

- core generation
- core import/edit
- practical font embedding, Unicode support, shaping, and subsetting
- practical Standard Security import/export coverage
- usable layout primitives

Remaining before `v1.0.0`:

- none

The public API audit/freeze step is complete. The supported v1 API boundary is defined in [public-api.md](public-api.md).
The docs/examples tightening step is complete.
The compatibility/limitations step is complete. The practical v1 support boundary is defined in [compatibility.md](compatibility.md).
The focused stabilization pass is complete, including real-fixture integration coverage.
The release-facing package polish step is complete.

Post-v1 backlog:

1. Deeper shaping and complex-script behavior.
2. Broader imported operator/resource and graphics-state fidelity work.
3. Broader encryption coverage outside the current Standard Security scope.
4. Larger layout and rendering ambitions beyond the current primitives.
