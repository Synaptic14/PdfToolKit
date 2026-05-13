# Public API

This file defines the public API surface for `PdfToolkit` v1.

The project/package name is `PdfToolkit` (`pdftookit/pdftoolkit`), and the PHP namespace is `PdfToolkit\\...`.

For `v1.0.0`, only the classes and namespaces listed here are considered stable, documented extension points. If a class is not listed here, it should be treated as internal implementation detail even if it is autoloadable.

## Stability Rule

For v1:

- Classes listed in this document are part of the supported public API.
- Classes not listed here may change, move, or disappear in minor releases until they are explicitly promoted.

## Primary Entry Points

- `PdfToolkit\Pdf`
- `PdfToolkit\Import\Importer`

These are the top-level entry points for creating, loading, importing, and measuring.

## Document Construction And Save

- `PdfToolkit\Core\Document`
- `PdfToolkit\Core\DocumentBuilder`
- `PdfToolkit\Core\Page`
- `PdfToolkit\Core\PageBuilder`
- `PdfToolkit\Core\PageRenderContext`
- `PdfToolkit\Core\DocumentMetadata`
- `PdfToolkit\Writer\WriteOptions`
- `PdfToolkit\Writer\StandardPermissions`

These classes define the supported generated-document workflow.

## Import And Modification

- `PdfToolkit\Import\ImportedDocument`
- `PdfToolkit\Import\ImportedPageCollection`
- `PdfToolkit\Import\ImportedPageEditor`
- `PdfToolkit\Import\ImportedAcroFormEditor`
- `PdfToolkit\Import\ImportReport`
- `PdfToolkit\Import\ImportSecurityInfo`
- `PdfToolkit\Import\ImportedFormField`

These classes define the supported imported-document workflow.

## Text And Fonts

- `PdfToolkit\Text\TextRun`
- `PdfToolkit\Text\FontReference`
- `PdfToolkit\Text\FontMetrics`

These are the stable text-facing types. Lower-level font parsing, shaping, glyph mapping, and subsetting classes are internal for v1.

## Graphics And Images

- `PdfToolkit\Graphics\Color`
- `PdfToolkit\Graphics\Line`
- `PdfToolkit\Graphics\Rectangle`
- `PdfToolkit\Image\ImagePlacement`

These are the stable generated-graphics value types. Image decoding and XObject plumbing remain internal.

## Forms, Annotations, And Navigation

- `PdfToolkit\Forms\FormField`
- `PdfToolkit\Annotations\LinkAnnotation`
- `PdfToolkit\Annotations\TextAnnotation`
- `PdfToolkit\Outline\OutlineItem`
- `PdfToolkit\Navigation\DocumentView`
- `PdfToolkit\Navigation\MarkInfo`
- `PdfToolkit\Navigation\NamedDestination`
- `PdfToolkit\Navigation\OpenAction`
- `PdfToolkit\Navigation\PageLabelRange`
- `PdfToolkit\Navigation\ViewerPreferences`

These are the stable value types for user-facing document features.

## Layout API

- `PdfToolkit\Layout\PageMargins`
- `PdfToolkit\Layout\PanelStyle`
- `PdfToolkit\Layout\TableCell`
- `PdfToolkit\Layout\TableColumn`
- `PdfToolkit\Layout\TableDataColumn`
- `PdfToolkit\Layout\TableStyle`
- `PdfToolkit\Layout\TextBlock`
- `PdfToolkit\Layout\TextFrame`

These value objects are part of the supported higher-level layout API.

## Exceptions

- `PdfToolkit\Core\PdfException`

## Internal Namespaces For V1

The following namespaces are internal for v1 unless an individual class is explicitly listed above:

- `PdfToolkit\Parser\*`
- `PdfToolkit\Writer\*` except `WriteOptions` and `StandardPermissions`
- `PdfToolkit\Text\*` except `TextRun`, `FontReference`, and `FontMetrics`
- `PdfToolkit\Core\*` helper/source classes not listed above
- `PdfToolkit\Image\*` except `ImagePlacement`

## Promotion Rule

A class should only be promoted into the public API when:

1. It is directly useful to package consumers.
2. We are prepared to document it.
3. We are prepared to keep it stable across the v1 line.
