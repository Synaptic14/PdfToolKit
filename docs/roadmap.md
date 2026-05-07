# Roadmap

This file is the source of truth for what counts as "done" for `PdfToolkit` v1.

The old roadmap mixed core feature delivery with open-ended spec hardening. That made progress feel endless even after the library had already become broadly useful. The v1 roadmap below is intentionally narrower: it is designed to be completed.

## Done For V1

These areas are already in place at a level that is strong enough for a `v1.0.0` release:

- Native parser/import pipeline for PDF versions `1.0` through `1.8`
- Fresh PDF generation for text, shapes, images, annotations, outlines, metadata, forms, and common document settings
- Import, modify, and save existing PDFs
- Imported-page overlays and common imported editing for text, coordinates, page geometry, annotations, forms, and graphics state
- Standard fonts plus embedded custom `TTF`/`TTC` fonts
- Unicode-capable generated text with subsetting and practical shaping support
- Layout primitives for flowing text, frames, panels, tables, grouped record tables, and multi-column composition
- Standard Security import/export support through the currently implemented RC4/AES paths
- PHP `8.2+` support

## Remaining Before V1.0.0

There are no remaining active v1 roadmap items.

If those items are complete, the v1 roadmap is complete.

The public API audit/freeze step is now complete. The v1 stability boundary is documented in [public-api.md](public-api.md).
The docs/examples tightening step is now complete.
The compatibility/limitations step is now complete. The practical v1 support boundary is documented in [compatibility.md](compatibility.md).
The focused stabilization pass is now complete, including real-fixture integration coverage for shipped example PDFs.
The release-facing package polish step is now complete, including package metadata cleanup, `LICENSE`, `CHANGELOG.md`, and [release-checklist.md](release-checklist.md).

## Post-V1 Backlog

The following are explicitly valuable, but they are no longer part of the active v1 finish line:

- Deeper OpenType shaping beyond the current practical GSUB/GPOS-oriented model
- HarfBuzz-class complex-script shaping
- Broader imported operator/resource editing beyond the common workflows already supported
- Additional obscure `ExtGState` and graphics-state fidelity work
- Broader encryption coverage beyond the currently implemented Standard Security scope
- Richer rendering/layout ambitions beyond the current flow, panel, and table primitives
- Further hardening for unusual real-world PDFs, fonts, and edge-case file structures

## Release Rule

For v1 planning, the rule is:

- If a task is not required to make the existing public feature set stable, documented, and releasable, it belongs in the post-v1 backlog.
