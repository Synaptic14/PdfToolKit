# Compatibility And Limitations

This document describes the practical v1 compatibility boundary for `PdfToolkit`.

The Composer package name is `pdftookit/pdftoolkit`, and the PHP namespace is `PdfToolkit\\...`.

It is intentionally narrower than “everything possible in the PDF spec.” The goal is to make it clear what package consumers can rely on today.

## Runtime Compatibility

- PHP `8.2+`
- Required extensions:
  - `ext-json`
  - `ext-mbstring`
- Optional capabilities depend on additional local tooling:
  - `OpenSSL` is required for AES-based encrypted PDF import/export paths
  - `GD` is used for some raster fallback image decoding paths
  - `ImageMagick` `magick` is used for trusted SVG raster fallback when `PDFTOOLKIT_ENABLE_SVG_MAGICK=1`

## PDF Version Compatibility

- Import/parser target: declared PDF versions `1.0` through `1.8`
- Representative import/save fixtures currently cover:
  - `1.3`
  - `1.4`
  - `1.7`
  - `1.8`

This means the package is intended to accept PDFs whose declared header version is in that range. It does not mean every advanced feature from every file will be fully editable.

## Supported Primary Workflows

### 1. Generate PDFs From Scratch

Supported at a practical v1 level:

- Pages
- Text
- Lines and rectangles
- Images
- Metadata
- Links and outlines
- Named destinations
- Viewer/startup settings
- AcroForm text fields and checkboxes
- Layout helpers for flowing text, panels, tables, and grouped record tables

### 2. Import Existing PDFs And Modify Them

Supported at a practical v1 level:

- Load existing PDFs
- Save imported PDFs back out
- Overlay text and shapes on imported pages
- Edit common imported text, coordinate, page-box, annotation, form, and graphics-state content
- Preserve a broad set of catalog/page structures when saving

### 3. Fill Imported AcroForm PDFs

Supported:

- Imported AcroForm field discovery
- Text-field updates
- Checkbox updates
- Hierarchical qualified field-name lookup
- Simple appearance regeneration
- Flattening

Not supported as a first-class v1 workflow:

- XFA form editing

## Fonts And Text

Supported:

- Standard PDF fonts
- Custom `TTF` fonts
- Custom `TTC` fonts, including explicit face selection
- Unicode-capable generated text
- Font subsetting
- Practical shaping support through the current GSUB/GPOS-oriented implementation

Limitations:

- The shaping stack is practical, not HarfBuzz-class
- Complex-script behavior beyond the current shaping model is post-v1 work
- Lower-level font parser/shaper/subsetter classes are internal API, not stable extension points

## Images

Supported:

- JPEG
- PNG
- WebP through raster fallback
- SVG through opt-in ImageMagick raster fallback for trusted input

Limitations:

- SVG support depends on local `magick` availability and is disabled by default unless `PDFTOOLKIT_ENABLE_SVG_MAGICK=1`
- Decoded PDF streams and decoded image payloads are subject to safety limits to reduce decompression-bomb risk
- Some image paths rely on raster fallback rather than native vector preservation

## Encryption

Supported:

- Standard Security encrypted PDF import/export
- RC4 paths currently implemented in the parser/writer
- AES paths currently implemented in the parser/writer
- Security reporting through `ImportSecurityInfo`

Limitations:

- The supported scope is the currently implemented Standard Security family
- Broader encryption coverage outside that scope is post-v1 work

## Coordinate Model

The public API uses a top-origin coordinate model:

- `y = 0` means the top of the page
- larger `y` values move downward

This is a deliberate public-API choice and differs from native PDF bottom-origin coordinates.

## Public API Stability Boundary

Only the classes documented in [public-api.md](public-api.md) are part of the supported stable v1 API surface.

Autoloadable classes outside that list should be treated as internal implementation detail.

## Known Practical Limitations

- XFA workflows are out of scope for v1
- Complex-script shaping beyond the current practical implementation is out of scope for v1
- Some rare imported PDF constructs are preserved more safely than they are deeply editable
- Some unsupported filtered imported streams may be preserved as raw bytes rather than fully decoded and rewritten
- Optional image/encryption capabilities depend on local runtime tools being available

## What “Compatible” Means In V1

For v1, compatibility means:

- the primary generation workflows are supported
- the primary import/edit workflows are supported
- the documented public API is stable
- unsupported or deeper spec corners are treated as limitations, not silent promises
