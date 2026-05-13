<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Core\Document;
use PdfToolkit\Core\ImportedContentStream;
use PdfToolkit\Core\ImportedPageSource;
use PdfToolkit\Core\PageRenderContext;
use PdfToolkit\Forms\FormField;
use PdfToolkit\Image\ImageReader;
use PdfToolkit\Image\ImageXObject;
use PdfToolkit\Navigation\DocumentView;
use PdfToolkit\Navigation\MarkInfo;
use PdfToolkit\Navigation\NamedDestination;
use PdfToolkit\Navigation\OpenAction;
use PdfToolkit\Navigation\PageLabelRange;
use PdfToolkit\Navigation\ViewerPreferences;
use PdfToolkit\Outline\OutlineItem;
use PdfToolkit\Parser\PdfLiteralString;
use PdfToolkit\Parser\PdfValueSerializer;
use PdfToolkit\Parser\PdfReference;
use PdfToolkit\Text\CharacterMap;
use PdfToolkit\Text\CompositeFontEncoding;
use PdfToolkit\Text\ParsedTrueTypeFont;
use PdfToolkit\Text\ResolvedFont;
use PdfToolkit\Text\TrueTypeFontParser;
use PdfToolkit\Text\TrueTypeFontSubsetResult;
use PdfToolkit\Text\TrueTypeFontSubsetter;

final class PdfWriter
{
    private ?PdfValueSerializer $serializer = null;
    private ?GeneratedContentCompiler $generatedContentCompiler = null;
    private ?ToUnicodeCMapBuilder $toUnicodeCMapBuilder = null;
    private ?ImageReader $imageReader = null;
    private ?TrueTypeFontParser $trueTypeFontParser = null;
    private ?TrueTypeFontSubsetter $trueTypeFontSubsetter = null;
    private array $documentPageHeights = [];

    private WriteOptions $options;

    public function write(Document $document, ?WriteOptions $options = null): string
    {
        $this->options = $options ?? new WriteOptions();
        $document = $this->applyPageChrome($document);
        $securityWriter = StandardSecurityWriter::fromWriteOptions($this->options);
        $objects = [
            3 => $this->buildInfoObject($document),
        ];
        $documentPages = $document->pages();
        $pageResourceAnalyses = $this->analyzePages($documentPages);
        $fontObjects = $this->buildFontObjects($pageResourceAnalyses);
        $objects += $fontObjects['objects'];
        $imageObjects = $this->buildImageObjects($pageResourceAnalyses, $fontObjects['nextObjectId']);
        $objects += $imageObjects['objects'];
        $pages = [];
        $pageObjectIds = [];
        $nextObjectId = $imageObjects['nextObjectId'];
        $acroFormFieldRefs = [];
        $acroFormFontObjectId = null;
        $importedAcroFormObjectId = null;
        $importedOutlineObjectId = null;
        $importedNameTreeObjectId = null;
        $importedCatalogMetadataObjectId = null;
        $importedPageLabelsObjectId = null;
        $importedViewerPreferencesObjectId = null;
        $importedOutputIntentsObjectId = null;
        $importedStructTreeObjectId = null;
        $generatedCatalogMetadataObjectId = null;
        $this->documentPageHeights = array_map(static fn (\PdfToolkit\Core\Page $page): float => $page->height(), $documentPages);

        foreach ($documentPages as $_) {
            $pageObjectIds[] = $nextObjectId++;
        }

        $importedPageObjectMap = [];

        foreach ($documentPages as $pageIndex => $page) {
            if ($page->importedSource() === null) {
                continue;
            }

            $importedPageObjectMap[$page->importedSource()->objectNumber] = $pageObjectIds[$pageIndex];
        }

        if ($document->importedAcroFormSource() !== null) {
            $importedAcroFormObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedAcroFormSource()->objectNumber,
                $document->importedAcroFormSource()->dependentObjects,
            );
        }

        if ($document->importedOutlineSource() !== null) {
            $importedOutlineObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedOutlineSource()->objectNumber,
                $document->importedOutlineSource()->dependentObjects,
                static fn (string $serializedValue): string => self::prepareOutlineDestinations($serializedValue, $importedPageObjectMap),
                static fn (string $serializedValue): string => self::finalizeOutlineDestinations($serializedValue, $importedPageObjectMap),
            );
        }

        if ($document->importedNameTreeSource() !== null) {
            $importedNameTreeObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedNameTreeSource()->objectNumber,
                $document->importedNameTreeSource()->dependentObjects,
            );
        }

        if ($document->importedCatalogMetadataSource() !== null && !$document->generateCatalogMetadata()) {
            $importedCatalogMetadataObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedCatalogMetadataSource()->objectNumber,
                $document->importedCatalogMetadataSource()->dependentObjects,
                serializedRootValue: $document->importedCatalogMetadataSource()->serializedValue,
            );
        }

        if ($document->generateCatalogMetadata()) {
            $generatedCatalogMetadataObjectId = $this->writeGeneratedCatalogMetadata($objects, $nextObjectId, $document);
        }

        if ($document->importedPageLabelsSource() !== null) {
            $importedPageLabelsObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedPageLabelsSource()->objectNumber,
                $document->importedPageLabelsSource()->dependentObjects,
                serializedRootValue: $document->importedPageLabelsSource()->serializedValue,
            );
        }

        if ($document->importedViewerPreferencesSource() !== null && $document->viewerPreferences() === null) {
            $importedViewerPreferencesObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedViewerPreferencesSource()->objectNumber,
                $document->importedViewerPreferencesSource()->dependentObjects,
                serializedRootValue: $document->importedViewerPreferencesSource()->serializedValue,
            );
        }

        if ($document->importedOutputIntentsSource() !== null) {
            $importedOutputIntentsObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedOutputIntentsSource()->objectNumber,
                $document->importedOutputIntentsSource()->dependentObjects,
                serializedRootValue: $document->importedOutputIntentsSource()->serializedValue,
            );
        }

        if ($document->importedStructTreeSource() !== null) {
            $importedStructTreeObjectId = $this->copyImportedObjectGraph(
                $objects,
                $nextObjectId,
                $document->importedStructTreeSource()->objectNumber,
                $document->importedStructTreeSource()->dependentObjects,
                serializedRootValue: $document->importedStructTreeSource()->serializedValue,
            );
        }

        if ($this->documentHasFormFields($document)) {
            $acroFormFontObjectId = $nextObjectId++;
            $objects[$acroFormFontObjectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        }

        foreach ($documentPages as $pageIndex => $page) {
            $pageObjectId = $pageObjectIds[$pageIndex];
            $pageResourceAnalysis = $pageResourceAnalyses[$pageIndex];

            if ($page->importedSource() !== null) {
                $this->writeImportedPage(
                    $objects,
                    $pages,
                    $pageObjectId,
                    $nextObjectId,
                    $page,
                    $importedPageObjectMap,
                    $pageObjectIds,
                    $this->pageFontResources($pageResourceAnalysis, $fontObjects['resourceMap']),
                    $this->pageFontTextEncodings($pageResourceAnalysis, $fontObjects['textEncodingMap']),
                    $this->pageImageResources($pageResourceAnalysis, $imageObjects['resourceMap']),
                    $acroFormFieldRefs
                );
                continue;
            }

            $this->writeGeneratedPage(
                $objects,
                $pages,
                $pageObjectId,
                $nextObjectId,
                $page,
                $pageObjectIds,
                $this->pageFontResources($pageResourceAnalysis, $fontObjects['resourceMap']),
                $this->pageFontTextEncodings($pageResourceAnalysis, $fontObjects['textEncodingMap']),
                $this->pageImageResources($pageResourceAnalysis, $imageObjects['resourceMap']),
                $acroFormFieldRefs
            );
        }

        $acroFormObjectId = null;

        if ($acroFormFieldRefs !== []) {
            $acroFormObjectId = $nextObjectId++;
            $objects[$acroFormObjectId] = $this->buildAcroFormObject($acroFormFieldRefs, $acroFormFontObjectId);
        } elseif ($importedAcroFormObjectId !== null) {
            $acroFormObjectId = $importedAcroFormObjectId;
        }

        $generatedOutlineObjectId = null;

        if ($document->outlineItems() !== []) {
            $generatedOutlineObjectId = $this->writeGeneratedOutlines($objects, $nextObjectId, $document->outlineItems(), $pageObjectIds, $documentPages);
        }

        $outlineObjectId = $generatedOutlineObjectId ?? $importedOutlineObjectId;
        $generatedNameTreeObjectId = null;

        if ($document->namedDestinations() !== []) {
            $generatedNameTreeObjectId = $this->writeGeneratedNameTree(
                $objects,
                $nextObjectId,
                $document->namedDestinations(),
                $pageObjectIds,
                $documentPages
            );
        }

        $nameTreeObjectId = $generatedNameTreeObjectId ?? $importedNameTreeObjectId;
        $generatedPageLabelsObjectId = null;

        if ($document->pageLabelRanges() !== []) {
            $generatedPageLabelsObjectId = $this->writeGeneratedPageLabels(
                $objects,
                $nextObjectId,
                $document->pageLabelRanges(),
                count($pageObjectIds)
            );
        }

        $pageLabelsObjectId = $generatedPageLabelsObjectId ?? $importedPageLabelsObjectId;
        $generatedViewerPreferencesObjectId = null;

        if ($document->viewerPreferences() !== null) {
            $generatedViewerPreferencesObjectId = $this->writeGeneratedViewerPreferences(
                $objects,
                $nextObjectId,
                $document->viewerPreferences()
            );
        }

        $viewerPreferencesObjectId = $generatedViewerPreferencesObjectId ?? $importedViewerPreferencesObjectId;

        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $pages),
            count($pages)
        );
        $openAction = $document->openAction() === null ? null : $this->buildOpenAction($document->openAction(), $pageObjectIds, $documentPages);
        $objects[1] = $this->buildCatalogObject(
            $acroFormObjectId,
            $outlineObjectId,
            $nameTreeObjectId,
            $generatedCatalogMetadataObjectId ?? $importedCatalogMetadataObjectId,
            $pageLabelsObjectId,
            $viewerPreferencesObjectId,
            $importedOutputIntentsObjectId,
            $importedStructTreeObjectId,
            $openAction,
            $document->markInfo(),
            $document->pageLayout(),
            $document->pageMode(),
            $document->language(),
            $document->uriBase()
        );

        if ($securityWriter !== null) {
            $encryptObjectId = max(array_keys($objects)) + 1;
            $encryption = $securityWriter->encryptObjects($objects, $encryptObjectId);
            $objects = $encryption['objects'];
            $objects[$encryptObjectId] = $encryption['encryptObject'];
        } else {
            $encryption = null;
        }

        ksort($objects);

        $body = "%PDF-1.7\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($body);
            $body .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
        }

        $xrefOffset = strlen($body);
        $maxObject = max(array_keys($objects));

        $body .= sprintf("xref\n0 %d\n", $maxObject + 1);
        $body .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObject; $i++) {
            $offset = $offsets[$i] ?? 0;
            $body .= sprintf("%010d 00000 n \n", $offset);
        }

        $body .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 3 0 R%s >>\nstartxref\n%d\n%%%%EOF",
            $maxObject + 1,
            $encryption['trailerSuffix'] ?? '',
            $xrefOffset
        );

        return $body;
    }

    private function applyPageChrome(Document $document): Document
    {
        $headerRenderer = $document->pageHeaderRenderer();
        $footerRenderer = $document->pageFooterRenderer();

        if ($headerRenderer === null && $footerRenderer === null) {
            return $document;
        }

        $renderedDocument = clone $document;
        $pageCount = count($renderedDocument->pages());

        foreach ($renderedDocument->pages() as $pageIndex => $page) {
            $context = new PageRenderContext(
                pageNumber: $pageIndex + 1,
                pageCount: $pageCount,
                pageWidth: $page->width(),
                pageHeight: $page->height(),
            );

            $headerRenderer?->__invoke($page, $context);
            $footerRenderer?->__invoke($page, $context);
        }

        return $renderedDocument;
    }

    private function writeGeneratedPage(
        array &$objects,
        array &$pages,
        int $pageObjectId,
        int &$nextObjectId,
        \PdfToolkit\Core\Page $page,
        array $pageObjectIds,
        array $pageFontResources,
        array $pageFontTextEncodings,
        array $pageImageResources,
        array &$acroFormFieldRefs,
    ): void
    {
        $content = $this->compilePageContent(
            $page,
            array_column($pageFontResources, 'resourceName', 'key'),
            array_column($pageFontTextEncodings, 'encoding', 'key'),
            array_column($pageImageResources, 'resourceName', 'key')
        );
        $contentObjectId = $nextObjectId++;
        $objects[$contentObjectId] = $this->buildStreamObject(['Length' => strlen($content)], $content, allowCompression: true);
        $annotationRefs = [
            ...$this->writeFormFields($objects, $nextObjectId, $page, $pageObjectId, $acroFormFieldRefs),
            ...$this->writeTextAnnotations($objects, $nextObjectId, $page, $pageObjectId),
            ...$this->writeLinkAnnotations($objects, $nextObjectId, $page, $pageObjectId, $pageObjectIds),
        ];
        $annotationPart = $annotationRefs === [] ? '' : ' /Annots [' . implode(' ', $annotationRefs) . ']';

        $pages[] = sprintf('%d 0 R', $pageObjectId);
        $rotationPart = $page->rotation() === 0 ? '' : sprintf(' /Rotate %d', $page->rotation());
        $boxPart = $this->pageBoxPart($page);
        $objects[$pageObjectId] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %F %F]%s%s /Resources %s /Contents %d 0 R%s >>",
            $page->width(),
            $page->height(),
            $rotationPart,
            $boxPart,
            $this->serializePdfValue($this->pageResourceDictionary($pageFontResources, $pageImageResources)),
            $contentObjectId,
            $annotationPart
        );
    }

    private function writeImportedPage(
        array &$objects,
        array &$pages,
        int $pageObjectId,
        int &$nextObjectId,
        \PdfToolkit\Core\Page $page,
        array $importedPageObjectMap,
        array $pageObjectIds,
        array $pageFontResources,
        array $pageFontTextEncodings,
        array $pageImageResources,
        array &$acroFormFieldRefs,
    ): void
    {
        $source = $page->importedSource();

        if ($source === null) {
            $this->writeGeneratedPage($objects, $pages, $pageObjectId, $nextObjectId, $page, $pageObjectIds, $pageFontResources, $pageFontTextEncodings, $pageImageResources, $acroFormFieldRefs);

            return;
        }

        $dependencyMap = [];

        foreach (array_keys($source->dependentObjects) as $sourceObjectNumber) {
            $dependencyMap[$sourceObjectNumber] = $nextObjectId++;
        }

        foreach ($source->dependentObjects as $sourceObjectNumber => $serializedValue) {
            $preparedValue = self::prepareOutlineDestinations($serializedValue, $importedPageObjectMap);
            $rewrittenValue = $this->rewriteIndirectReferences($preparedValue, $dependencyMap);
            $objects[$dependencyMap[$sourceObjectNumber]] = self::finalizeOutlineDestinations($rewrittenValue, $importedPageObjectMap);
        }

        $contentRefs = [];

        foreach ($source->contentStreams as $stream) {
            $contentObjectId = $nextObjectId++;
            $contentRefs[] = $contentObjectId;
            $objects[$contentObjectId] = $this->buildImportedStreamObject($stream, $dependencyMap);
        }

        $overlayContent = $this->compilePageContent(
            $page,
            array_column($pageFontResources, 'resourceName', 'key'),
            array_column($pageFontTextEncodings, 'encoding', 'key'),
            array_column($pageImageResources, 'resourceName', 'key')
        );

        if ($overlayContent !== '') {
            if ($contentRefs !== []) {
                $pushStateObjectId = $nextObjectId++;
                $popStateObjectId = $nextObjectId++;
                $objects[$pushStateObjectId] = $this->buildStreamObject(['Length' => 1], 'q', allowCompression: true);
                $objects[$popStateObjectId] = $this->buildStreamObject(['Length' => 1], 'Q', allowCompression: true);
                array_unshift($contentRefs, $pushStateObjectId);
                $contentRefs[] = $popStateObjectId;
            }

            $overlayObjectId = $nextObjectId++;
            $contentRefs[] = $overlayObjectId;
            $objects[$overlayObjectId] = $this->buildStreamObject(['Length' => strlen($overlayContent)], $overlayContent, allowCompression: true);
        }

        if ($contentRefs === []) {
            $emptyObjectId = $nextObjectId++;
            $contentRefs[] = $emptyObjectId;
            $objects[$emptyObjectId] = $this->buildStreamObject(['Length' => 0], '', allowCompression: true);
        }

        $resources = $this->mergeImportedResources($source, $dependencyMap, $pageFontResources, $pageImageResources);
        $resourcesString = $this->serializePdfValue($resources);
        $contentsString = count($contentRefs) === 1
            ? sprintf('%d 0 R', $contentRefs[0])
            : '[' . implode(' ', array_map(static fn (int $id): string => sprintf('%d 0 R', $id), $contentRefs)) . ']';
        $generatedAnnotationRefs = [
            ...$this->writeFormFields($objects, $nextObjectId, $page, $pageObjectId, $acroFormFieldRefs),
            ...$this->writeTextAnnotations($objects, $nextObjectId, $page, $pageObjectId),
            ...$this->writeLinkAnnotations($objects, $nextObjectId, $page, $pageObjectId, $pageObjectIds),
        ];
        $annotations = $this->mergeAnnotationValues(
            $source->pageDictionary['Annots'] ?? null,
            $dependencyMap,
            $generatedAnnotationRefs,
            $importedPageObjectMap
        );
        $annotationPart = $annotations === null ? '' : ' /Annots ' . $this->serializePdfValue($annotations);

        $pages[] = sprintf('%d 0 R', $pageObjectId);
        $rotationPart = $page->rotation() === 0 ? '' : sprintf(' /Rotate %d', $page->rotation());
        $boxPart = $this->pageBoxPart($page);
        $objects[$pageObjectId] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %F %F]%s%s /Resources %s /Contents %s%s >>",
            $page->width(),
            $page->height(),
            $rotationPart,
            $boxPart,
            $resourcesString,
            $contentsString,
            $annotationPart
        );
    }

    private function buildCatalogObject(
        ?int $acroFormObjectId,
        ?int $outlineObjectId = null,
        ?int $nameTreeObjectId = null,
        ?int $catalogMetadataObjectId = null,
        ?int $pageLabelsObjectId = null,
        ?int $viewerPreferencesObjectId = null,
        ?int $outputIntentsObjectId = null,
        ?int $structTreeObjectId = null,
        mixed $openAction = null,
        ?MarkInfo $markInfo = null,
        ?string $pageLayout = null,
        ?string $pageMode = null,
        ?string $language = null,
        ?string $uriBase = null,
    ): string
    {
        $catalog = [
            'Type' => 'Catalog',
            'Pages' => new PdfReference(2, 0),
        ];

        if ($acroFormObjectId !== null) {
            $catalog['AcroForm'] = new PdfReference($acroFormObjectId, 0);
        }

        if ($outlineObjectId !== null) {
            $catalog['Outlines'] = new PdfReference($outlineObjectId, 0);
            $catalog['PageMode'] = $pageMode ?? DocumentView::PAGE_MODE_USE_OUTLINES;
        } elseif ($pageMode !== null) {
            $catalog['PageMode'] = $pageMode;
        }

        if ($nameTreeObjectId !== null) {
            $catalog['Names'] = new PdfReference($nameTreeObjectId, 0);
        }

        if ($catalogMetadataObjectId !== null) {
            $catalog['Metadata'] = new PdfReference($catalogMetadataObjectId, 0);
        }

        if ($pageLabelsObjectId !== null) {
            $catalog['PageLabels'] = new PdfReference($pageLabelsObjectId, 0);
        }

        if ($viewerPreferencesObjectId !== null) {
            $catalog['ViewerPreferences'] = new PdfReference($viewerPreferencesObjectId, 0);
        }

        if ($outputIntentsObjectId !== null) {
            $catalog['OutputIntents'] = new PdfReference($outputIntentsObjectId, 0);
        }

        if ($structTreeObjectId !== null) {
            $catalog['StructTreeRoot'] = new PdfReference($structTreeObjectId, 0);
        }

        if ($openAction !== null) {
            $catalog['OpenAction'] = $openAction;
        }

        if ($markInfo !== null) {
            $catalog['MarkInfo'] = array_filter([
                'Marked' => $markInfo->marked,
                'UserProperties' => $markInfo->userProperties,
                'Suspects' => $markInfo->suspects,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($pageLayout !== null) {
            $catalog['PageLayout'] = $pageLayout;
        }

        if ($language !== null) {
            $catalog['Lang'] = new PdfLiteralString($language);
        }

        if ($uriBase !== null) {
            $catalog['URI'] = [
                'Base' => new PdfLiteralString($uriBase),
            ];
        }

        return $this->serializePdfValue($catalog);
    }

    /**
     * @param list<int> $pageObjectIds
     */
    private function buildOpenAction(OpenAction $openAction, array $pageObjectIds, array $documentPages): mixed
    {
        if ($openAction->destinationName !== null) {
            return new PdfLiteralString($openAction->destinationName);
        }

        if ($openAction->pageNumber === null) {
            throw new \LogicException('Open action must define either a page or named destination.');
        }

        $pageObjectId = $pageObjectIds[$openAction->pageNumber - 1] ?? null;

        if ($pageObjectId === null) {
            throw new \LogicException(sprintf('Open action points to missing page %d.', $openAction->pageNumber));
        }

        $pageHeight = $documentPages[$openAction->pageNumber - 1]?->height() ?? null;

        return $this->buildDestination($pageObjectId, $pageHeight, $openAction->left, $openAction->top, $openAction->zoom);
    }

    /**
     * @param array<int, string> $objects
     * @param list<OutlineItem> $outlineItems
     * @param list<int> $pageObjectIds
     */
    private function writeGeneratedOutlines(
        array &$objects,
        int &$nextObjectId,
        array $outlineItems,
        array $pageObjectIds,
        array $documentPages,
    ): int {
        $rootObjectId = $nextObjectId++;
        $tree = $this->buildOutlineTree($outlineItems);
        $itemObjectIds = [];

        foreach (array_keys($outlineItems) as $index) {
            $itemObjectIds[$index] = $nextObjectId++;
        }

        foreach ($outlineItems as $index => $outlineItem) {
            $pageObjectId = $pageObjectIds[$outlineItem->pageNumber - 1] ?? null;

            if ($pageObjectId === null) {
                throw new \LogicException(sprintf(
                    'Outline "%s" points to missing page %d.',
                    $outlineItem->title,
                    $outlineItem->pageNumber
                ));
            }

            $dictionary = [
                'Title' => new PdfLiteralString($outlineItem->title),
                'Dest' => $this->buildDestination(
                    $pageObjectId,
                    $documentPages[$outlineItem->pageNumber - 1]?->height() ?? null,
                    $outlineItem->left,
                    $outlineItem->top,
                    $outlineItem->zoom
                ),
            ];
            $parentIndex = $tree['parent'][$index] ?? null;
            $children = $tree['children'][$index] ?? [];

            $dictionary['Parent'] = $parentIndex === null
                ? new PdfReference($rootObjectId, 0)
                : new PdfReference($itemObjectIds[$parentIndex], 0);

            if (($tree['prev'][$index] ?? null) !== null) {
                $dictionary['Prev'] = new PdfReference($itemObjectIds[$tree['prev'][$index]], 0);
            }

            if (($tree['next'][$index] ?? null) !== null) {
                $dictionary['Next'] = new PdfReference($itemObjectIds[$tree['next'][$index]], 0);
            }

            if ($children !== []) {
                $dictionary['First'] = new PdfReference($itemObjectIds[$children[0]], 0);
                $dictionary['Last'] = new PdfReference($itemObjectIds[$children[array_key_last($children)]], 0);
                $dictionary['Count'] = $this->outlineDescendantCount($index, $tree['children']);
            }

            $objects[$itemObjectIds[$index]] = $this->serializePdfValue($dictionary);
        }

        $rootChildren = $tree['children'][null] ?? [];
        $objects[$rootObjectId] = $this->serializePdfValue([
            'Type' => 'Outlines',
            'First' => new PdfReference($itemObjectIds[$rootChildren[0]], 0),
            'Last' => new PdfReference($itemObjectIds[$rootChildren[array_key_last($rootChildren)]], 0),
            'Count' => count($outlineItems),
        ]);

        return $rootObjectId;
    }

    /**
     * @param array<int, string> $objects
     * @param list<NamedDestination> $destinations
     * @param list<int> $pageObjectIds
     */
    private function writeGeneratedNameTree(
        array &$objects,
        int &$nextObjectId,
        array $destinations,
        array $pageObjectIds,
        array $documentPages,
    ): int {
        $destsObjectId = $nextObjectId++;
        $namesObjectId = $nextObjectId++;
        $names = [];

        usort($destinations, static fn (NamedDestination $a, NamedDestination $b): int => $a->name <=> $b->name);

        foreach ($destinations as $destination) {
            $pageObjectId = $pageObjectIds[$destination->pageNumber - 1] ?? null;

            if ($pageObjectId === null) {
                throw new \LogicException(sprintf(
                    'Named destination "%s" points to missing page %d.',
                    $destination->name,
                    $destination->pageNumber
                ));
            }

            $names[] = new PdfLiteralString($destination->name);
            $names[] = $this->buildDestination(
                $pageObjectId,
                $documentPages[$destination->pageNumber - 1]?->height() ?? null,
                $destination->left,
                $destination->top,
                $destination->zoom
            );
        }

        $objects[$destsObjectId] = $this->serializePdfValue(['Names' => $names]);
        $objects[$namesObjectId] = $this->serializePdfValue(['Dests' => new PdfReference($destsObjectId, 0)]);

        return $namesObjectId;
    }

    /**
     * @param array<int, string> $objects
     * @param list<PageLabelRange> $ranges
     */
    private function writeGeneratedPageLabels(
        array &$objects,
        int &$nextObjectId,
        array $ranges,
        int $pageCount,
    ): int {
        $objectId = $nextObjectId++;
        $nums = [];

        usort($ranges, static fn (PageLabelRange $a, PageLabelRange $b): int => $a->startPage <=> $b->startPage);

        foreach ($ranges as $range) {
            if ($range->startPage > $pageCount) {
                throw new \LogicException(sprintf('Page label range points to missing page %d.', $range->startPage));
            }

            $dictionary = [];

            if ($range->style !== null) {
                $dictionary['S'] = $range->style;
            }

            if ($range->prefix !== null) {
                $dictionary['P'] = new PdfLiteralString($range->prefix);
            }

            if ($range->startNumber !== 1) {
                $dictionary['St'] = $range->startNumber;
            }

            $nums[] = $range->startPage - 1;
            $nums[] = $dictionary;
        }

        $objects[$objectId] = $this->serializePdfValue(['Nums' => $nums]);

        return $objectId;
    }

    /**
     * @param array<int, string> $objects
     */
    private function writeGeneratedViewerPreferences(
        array &$objects,
        int &$nextObjectId,
        ViewerPreferences $preferences,
    ): int {
        $objectId = $nextObjectId++;
        $dictionary = [];

        foreach ([
            'HideToolbar' => $preferences->hideToolbar,
            'HideMenubar' => $preferences->hideMenubar,
            'HideWindowUI' => $preferences->hideWindowUI,
            'FitWindow' => $preferences->fitWindow,
            'CenterWindow' => $preferences->centerWindow,
            'DisplayDocTitle' => $preferences->displayDocTitle,
        ] as $name => $value) {
            if ($value !== null) {
                $dictionary[$name] = $value;
            }
        }

        if ($preferences->printScaling !== null) {
            $dictionary['PrintScaling'] = $preferences->printScaling;
        }

        $objects[$objectId] = $this->serializePdfValue($dictionary);

        return $objectId;
    }

    /**
     * @param array<int, string> $objects
     */
    private function writeGeneratedCatalogMetadata(
        array &$objects,
        int &$nextObjectId,
        Document $document,
    ): int {
        $objectId = $nextObjectId++;
        $contents = $this->buildGeneratedCatalogMetadataXml($document->metadata());
        $objects[$objectId] = $this->buildStreamObject([
            'Type' => 'Metadata',
            'Subtype' => 'XML',
            'Length' => strlen($contents),
        ], $contents, allowCompression: false);

        return $objectId;
    }

    private function buildGeneratedCatalogMetadataXml(\PdfToolkit\Core\DocumentMetadata $metadata): string
    {
        $title = $metadata->title === null ? '' : '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $this->escapeXml($metadata->title) . '</rdf:li></rdf:Alt></dc:title>';
        $author = $metadata->author === null ? '' : '<dc:creator><rdf:Seq><rdf:li>' . $this->escapeXml($metadata->author) . '</rdf:li></rdf:Seq></dc:creator>';
        $subject = $metadata->subject === null ? '' : '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $this->escapeXml($metadata->subject) . '</rdf:li></rdf:Alt></dc:description>';

        $keywords = '';

        if ($metadata->keywords !== []) {
            $keywordItems = array_map(
                fn (string $keyword): string => '<rdf:li>' . $this->escapeXml($keyword) . '</rdf:li>',
                $metadata->keywords
            );
            $keywords = '<pdf:Keywords><rdf:Bag>' . implode('', $keywordItems) . '</rdf:Bag></pdf:Keywords>';
        }

        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
            . $title
            . $author
            . $subject
            . $keywords
            . '</rdf:Description>'
            . '</rdf:RDF>'
            . '</x:xmpmeta>'
            . '<?xpacket end="w"?>';
    }

    /**
     * @param list<OutlineItem> $outlineItems
     * @return array{parent: array<int, int|null>, children: array<int|string, list<int>>, prev: array<int, int|null>, next: array<int, int|null>}
     */
    private function buildOutlineTree(array $outlineItems): array
    {
        $lastAtLevel = [];
        $parent = [];
        $children = [null => []];

        foreach ($outlineItems as $index => $outlineItem) {
            $level = $outlineItem->level;

            if ($level > 0 && !isset($lastAtLevel[$level - 1])) {
                $level = 0;
            }

            $parentIndex = $level === 0 ? null : $lastAtLevel[$level - 1];
            $parent[$index] = $parentIndex;
            $children[$parentIndex][] = $index;
            $children[$index] ??= [];
            $lastAtLevel[$level] = $index;

            foreach (array_keys($lastAtLevel) as $trackedLevel) {
                if ($trackedLevel > $level) {
                    unset($lastAtLevel[$trackedLevel]);
                }
            }
        }

        $prev = [];
        $next = [];

        foreach ($children as $siblings) {
            foreach ($siblings as $position => $index) {
                $prev[$index] = $siblings[$position - 1] ?? null;
                $next[$index] = $siblings[$position + 1] ?? null;
            }
        }

        return [
            'parent' => $parent,
            'children' => $children,
            'prev' => $prev,
            'next' => $next,
        ];
    }

    /**
     * @param array<int|string, list<int>> $children
     */
    private function outlineDescendantCount(int $index, array $children): int
    {
        $count = 0;

        foreach ($children[$index] ?? [] as $childIndex) {
            $count++;
            $count += $this->outlineDescendantCount($childIndex, $children);
        }

        return $count;
    }

    private function pageBoxPart(\PdfToolkit\Core\Page $page): string
    {
        $parts = [];

        foreach ($page->pageBoxes() as $name => $box) {
            if (!in_array($name, ['CropBox', 'BleedBox', 'TrimBox', 'ArtBox'], true) || count($box) !== 4) {
                continue;
            }

            $parts[] = sprintf(' /%s %s', $name, $this->serializePdfValue($box));
        }

        return implode('', $parts);
    }

    private function compilePageContent(\PdfToolkit\Core\Page $page, array $fontResourceNames = [], array $fontTextEncodings = [], array $imageResourceNames = []): string
    {
        return $this->generatedContentCompiler()->compile($page, $fontResourceNames, $imageResourceNames, $fontTextEncodings);
    }

    /**
     * @param list<\PdfToolkit\Core\Page> $pages
     * @return list<array{
     *     fonts: array<string, ResolvedFont>,
     *     glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>,
     *     images: array<string, \PdfToolkit\Image\ImagePlacement>
     * }>
     */
    private function analyzePages(array $pages): array
    {
        $analyses = [];

        foreach ($pages as $page) {
            $analyses[] = $this->generatedContentCompiler()->analyzePage($page);
        }

        return $analyses;
    }

    private function generatedContentCompiler(): GeneratedContentCompiler
    {
        return $this->generatedContentCompiler ??= new GeneratedContentCompiler(
            new \PdfToolkit\Text\FontRegistry($this->trueTypeFontParser()),
            $this->trueTypeFontParser(),
        );
    }

    private function buildInfoObject(Document $document): string
    {
        $metadata = $document->metadata();
        $parts = [];

        if ($metadata->title !== null) {
            $parts[] = '/Title (' . $this->escapeLiteral($metadata->title) . ')';
        }

        if ($metadata->author !== null) {
            $parts[] = '/Author (' . $this->escapeLiteral($metadata->author) . ')';
        }

        if ($metadata->subject !== null) {
            $parts[] = '/Subject (' . $this->escapeLiteral($metadata->subject) . ')';
        }

        if ($metadata->keywords !== []) {
            $parts[] = '/Keywords (' . $this->escapeLiteral(implode(', ', $metadata->keywords)) . ')';
        }

        return '<< ' . implode(' ', $parts) . ' >>';
    }

    private function documentHasFormFields(Document $document): bool
    {
        foreach ($document->pages() as $page) {
            if ($page->formFields() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $fieldRefs
     */
    private function buildAcroFormObject(array $fieldRefs, ?int $fontObjectId): string
    {
        $dictionary = [
            'Fields' => array_map(
                static fn (string $fieldRef): PdfReference => self::referenceFromString($fieldRef),
                $fieldRefs
            ),
            'NeedAppearances' => true,
        ];

        if ($fontObjectId !== null) {
            $dictionary['DR'] = [
                'Font' => [
                    'Helv' => new PdfReference($fontObjectId, 0),
                ],
            ];
            $dictionary['DA'] = new PdfLiteralString('/Helv 0 Tf 0 g');
        }

        return $this->serializePdfValue($dictionary);
    }

    /**
     * @param array<int, string> $objects
     * @param list<string> $acroFormFieldRefs
     * @return list<string>
     */
    private function writeFormFields(
        array &$objects,
        int &$nextObjectId,
        \PdfToolkit\Core\Page $page,
        int $pageObjectId,
        array &$acroFormFieldRefs,
    ): array {
        $annotationRefs = [];

        foreach ($page->formFields() as $field) {
            $fieldObjectId = $nextObjectId++;
            $objects[$fieldObjectId] = $this->buildWidgetFieldObject($field, $pageObjectId, $page->height());
            $reference = sprintf('%d 0 R', $fieldObjectId);
            $annotationRefs[] = $reference;
            $acroFormFieldRefs[] = $reference;
        }

        return $annotationRefs;
    }

    private function buildWidgetFieldObject(FormField $field, int $pageObjectId, float $pageHeight): string
    {
        $dictionary = [
            'Type' => 'Annot',
            'Subtype' => 'Widget',
            'P' => new PdfReference($pageObjectId, 0),
            'T' => new PdfLiteralString((string) $field->name),
            'Rect' => $this->topLeftRect($field->x, $field->y, $field->width, $field->height, $pageHeight),
            'F' => 4,
            'DA' => new PdfLiteralString('/Helv 12 Tf 0 g'),
        ];

        $type = strtolower($field->type);

        if (in_array($type, ['text', 'tx', 'textfield'], true)) {
            $dictionary['FT'] = 'Tx';
            $dictionary['V'] = new PdfLiteralString(isset($field->options['value']) ? (string) $field->options['value'] : '');
        } elseif (in_array($type, ['checkbox', 'check', 'btn'], true)) {
            $checked = (bool) ($field->options['checked'] ?? false);
            $dictionary['FT'] = 'Btn';
            $dictionary['V'] = $checked ? 'Yes' : 'Off';
            $dictionary['AS'] = $checked ? 'Yes' : 'Off';
        } else {
            $dictionary['FT'] = 'Tx';
            $dictionary['V'] = new PdfLiteralString(isset($field->options['value']) ? (string) $field->options['value'] : '');
        }

        return $this->serializePdfValue($dictionary);
    }

    /**
     * @param array<int, string> $objects
     * @return list<string>
     */
    private function writeTextAnnotations(
        array &$objects,
        int &$nextObjectId,
        \PdfToolkit\Core\Page $page,
        int $pageObjectId,
    ): array {
        $annotationRefs = [];

        foreach ($page->textAnnotations() as $annotation) {
            $annotationObjectId = $nextObjectId++;
            $objects[$annotationObjectId] = $this->buildTextAnnotationObject($annotation, $pageObjectId, $page->height());
            $annotationRefs[] = sprintf('%d 0 R', $annotationObjectId);
        }

        return $annotationRefs;
    }

    private function buildTextAnnotationObject(TextAnnotation $annotation, int $pageObjectId, float $pageHeight): string
    {
        return $this->serializePdfValue([
            'Type' => 'Annot',
            'Subtype' => 'Text',
            'P' => new PdfReference($pageObjectId, 0),
            'Rect' => $this->topLeftRect($annotation->x, $annotation->y, $annotation->width, $annotation->height, $pageHeight),
            'Contents' => new PdfLiteralString($annotation->contents),
            'Open' => $annotation->open,
            'Name' => $annotation->icon,
        ]);
    }

    /**
     * @param array<int, string> $objects
     * @return list<string>
     */
    private function writeLinkAnnotations(
        array &$objects,
        int &$nextObjectId,
        \PdfToolkit\Core\Page $page,
        int $pageObjectId,
        array $pageObjectIds,
    ): array {
        $annotationRefs = [];

        foreach ($page->linkAnnotations() as $annotation) {
            $annotationObjectId = $nextObjectId++;
            $objects[$annotationObjectId] = $this->buildLinkAnnotationObject($annotation, $pageObjectId, $pageObjectIds, $page->height());
            $annotationRefs[] = sprintf('%d 0 R', $annotationObjectId);
        }

        return $annotationRefs;
    }

    /**
     * @param list<int> $pageObjectIds
     */
    private function buildLinkAnnotationObject(LinkAnnotation $annotation, int $pageObjectId, array $pageObjectIds, float $currentPageHeight): string
    {
        $dictionary = [
            'Type' => 'Annot',
            'Subtype' => 'Link',
            'P' => new PdfReference($pageObjectId, 0),
            'Rect' => $this->topLeftRect($annotation->x, $annotation->y, $annotation->width, $annotation->height, $currentPageHeight),
            'Border' => $annotation->border ? [0, 0, 1] : [0, 0, 0],
        ];

        if ($annotation->pageNumber !== null) {
            $targetPageObjectId = $pageObjectIds[$annotation->pageNumber - 1] ?? null;

            if ($targetPageObjectId === null) {
                throw new \LogicException(sprintf('Link annotation points to missing page %d.', $annotation->pageNumber));
            }

            $dictionary['Dest'] = $this->buildDestination(
                $targetPageObjectId,
                $this->pageHeightForObjectId($targetPageObjectId, $pageObjectIds, $currentPageHeight),
                $annotation->left,
                $annotation->top,
                $annotation->zoom
            );
        } elseif ($annotation->destinationName !== null) {
            $dictionary['Dest'] = new PdfLiteralString($annotation->destinationName);
        } elseif ($annotation->uri !== null) {
            $dictionary['A'] = [
                'S' => 'URI',
                'URI' => new PdfLiteralString($annotation->uri),
            ];
        } else {
            throw new \LogicException('Link annotation must define either a URI or a page destination.');
        }

        return $this->serializePdfValue($dictionary);
    }

    /**
     * @return list<mixed>
     */
    private function buildDestination(int $pageObjectId, ?float $pageHeight = null, ?float $left = null, ?float $top = null, ?float $zoom = null): array
    {
        return [new PdfReference($pageObjectId, 0), 'XYZ', $left, $this->destinationTop($top, $pageHeight), $zoom];
    }

    /**
     * @return list<float>
     */
    private function topLeftRect(float $x, float $y, float $width, float $height, float $pageHeight): array
    {
        $lowerY = max(0.0, $pageHeight - $y - $height);
        $upperY = max($lowerY, $pageHeight - $y);

        return [$x, $lowerY, $x + $width, $upperY];
    }

    private function destinationTop(?float $top, ?float $pageHeight): ?float
    {
        if ($top === null || $pageHeight === null) {
            return $top;
        }

        return $pageHeight - $top;
    }

    private function pageHeightForObjectId(int $pageObjectId, array $pageObjectIds, float $fallback): float
    {
        $index = array_search($pageObjectId, $pageObjectIds, true);

        if ($index === false) {
            return $fallback;
        }

        return $this->documentPageHeights[$index] ?? $fallback;
    }

    private static function referenceFromString(string $reference): PdfReference
    {
        if (preg_match('/^(\d+)\s+(\d+)\s+R$/', $reference, $matches) !== 1) {
            throw new \LogicException(sprintf('Invalid PDF reference string: %s', $reference));
        }

        return new PdfReference((int) $matches[1], (int) $matches[2]);
    }

    private function escapeLiteral(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function buildStreamObject(array $dictionary, string $contents, bool $allowCompression = false): string
    {
        if ($allowCompression && $this->options->compressStreams && $contents !== '') {
            if (!function_exists('zlib_encode')) {
                throw new \RuntimeException('zlib extension is required to compress PDF streams.');
            }

            $encoded = zlib_encode($contents, ZLIB_ENCODING_DEFLATE);

            if ($encoded === false) {
                throw new \RuntimeException('Unable to compress PDF stream.');
            }

            $contents = $encoded;
            $dictionary['Filter'] = 'FlateDecode';
        }

        $dictionary['Length'] = strlen($contents);

        return $this->serializePdfValue($dictionary) . "\nstream\n" . $contents . "\nendstream";
    }

    private function buildImportedStreamObject(ImportedContentStream $stream, array $dependencyMap): string
    {
        $dictionary = $stream->dictionary;
        unset($dictionary['__warnings']);
        $dictionary['Length'] = strlen($stream->contents);
        $dictionary = $this->remapPdfValue($dictionary, $dependencyMap);

        return $this->buildStreamObject(
            $dictionary,
            $stream->contents,
            allowCompression: !isset($dictionary['Filter'])
        );
    }

    /**
     * @param array<int, int> $dependencyMap
     * @return array<string, mixed>
     */
    private function mergeImportedResources(ImportedPageSource $source, array $dependencyMap, array $pageFontResources, array $pageImageResources): array
    {
        $resources = $this->remapPdfValue($source->resourceDictionary ?? [], $dependencyMap);

        if (!isset($resources['Font']) || !is_array($resources['Font'])) {
            $resources['Font'] = [];
        }

        foreach ($this->fontResourceDictionary($pageFontResources) as $resourceName => $reference) {
            $resources['Font'][$resourceName] = $reference;
        }

        if ($pageImageResources !== []) {
            if (!isset($resources['XObject']) || !is_array($resources['XObject'])) {
                $resources['XObject'] = [];
            }

            foreach ($this->imageResourceDictionary($pageImageResources) as $resourceName => $reference) {
                $resources['XObject'][$resourceName] = $reference;
            }
        }

        return $resources;
    }

    private function pageResourceDictionary(array $pageFontResources, array $pageImageResources): array
    {
        $resources = [];

        $fonts = $this->fontResourceDictionary($pageFontResources);

        if ($fonts !== []) {
            $resources['Font'] = $fonts;
        }

        $images = $this->imageResourceDictionary($pageImageResources);

        if ($images !== []) {
            $resources['XObject'] = $images;
        }

        return $resources;
    }

    /**
     * @param array<int, int> $dependencyMap
     * @param list<string> $generatedAnnotationRefs
     * @param array<int, int> $pageObjectMap
     */
    private function mergeAnnotationValues(
        mixed $importedAnnotations,
        array $dependencyMap,
        array $generatedAnnotationRefs,
        array $pageObjectMap = [],
    ): mixed
    {
        $generated = array_map(
            static fn (string $reference): PdfReference => self::referenceFromString($reference),
            $generatedAnnotationRefs
        );

        if ($importedAnnotations === null) {
            return $generated === [] ? null : $generated;
        }

        $imported = $this->remapAnnotationValue($importedAnnotations, $dependencyMap, $pageObjectMap);

        if ($generated === []) {
            return $imported;
        }

        if (is_array($imported) && array_is_list($imported)) {
            return [...$imported, ...$generated];
        }

        return [$imported, ...$generated];
    }

    /**
     * @return array{objects: array<int, string>, resourceMap: array<string, array{key: string, resourceName: string, objectId: int, font: ResolvedFont}>, textEncodingMap: array<string, CompositeFontEncoding>, nextObjectId: int}
     */
    /**
     * @param list<array{
     *     fonts: array<string, ResolvedFont>,
     *     glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>,
     *     images: array<string, \PdfToolkit\Image\ImagePlacement>
     * }> $pageResourceAnalyses
     */
    private function buildFontObjects(array $pageResourceAnalyses): array
    {
        $fonts = [];
        $glyphs = [];

        foreach ($pageResourceAnalyses as $pageResourceAnalysis) {
            foreach ($pageResourceAnalysis['fonts'] as $key => $font) {
                $fonts[$key] = $font;
            }

            foreach ($pageResourceAnalysis['glyphs'] as $key => $usedGlyphs) {
                $glyphs[$key] ??= [];

                foreach ($usedGlyphs->keys() as $glyphKey) {
                    $glyphs[$key][$glyphKey] = CharacterMap::character($glyphKey);
                }
            }
        }

        ksort($fonts);
        $objects = [];
        $resourceMap = [];
        $textEncodingMap = [];
        $nextObjectId = 4;
        $resourceIndex = 1;

        foreach ($fonts as $key => $font) {
            $objectId = $nextObjectId++;
            $characterEntries = $glyphs[$key] ?? [];
            $characterKeys = array_keys($characterEntries);
            $characters = array_values($characterEntries);
            sort($characters);
            $toUnicodeObjectId = null;
            $resourceName = 'PT_F' . $resourceIndex++;

            if ($font->sourcePath !== null) {
                $requiresComposite = $this->requiresCompositeTrueTypeFont($characterKeys);
                $parsedTrueType = $this->trueTypeFontParser()->parse($font->sourcePath, $font->faceIndex);

                if ($requiresComposite) {
                    $characterToCid = [];
                    $nextCid = 1;

                    foreach (array_keys($characterEntries) as $characterKey) {
                        $characterToCid[$characterKey] = $nextCid++;
                    }

                    if ($characterToCid !== []) {
                        $toUnicodeObjectId = $nextObjectId++;
                        $cmap = $this->toUnicodeCMapBuilder()->buildComposite($characterToCid);
                        $objects[$toUnicodeObjectId] = $this->buildStreamObject(['Length' => strlen($cmap)], $cmap);
                    }

                    $compositeFontObjects = $this->buildCompositeTrueTypeFontObjects(
                        $nextObjectId,
                        $font,
                        $characterToCid,
                        $parsedTrueType,
                        $toUnicodeObjectId
                    );
                    $objects += $compositeFontObjects['objects'];
                    $resourceMap[$key] = [
                        'key' => $key,
                        'resourceName' => $resourceName,
                        'objectId' => $compositeFontObjects['fontObjectId'],
                        'font' => $font,
                    ];
                    $textEncodingMap[$key] = $compositeFontObjects['textEncoding'];
                    $nextObjectId = $compositeFontObjects['nextObjectId'];

                    continue;
                }

                if ($characters !== []) {
                    $toUnicodeObjectId = $nextObjectId++;
                    $cmap = $this->toUnicodeCMapBuilder()->build($characters);
                    $objects[$toUnicodeObjectId] = $this->buildStreamObject(['Length' => strlen($cmap)], $cmap);
                }

                foreach ($characters as $character) {
                    $codePoint = mb_ord($character);

                    if ($codePoint < 32 || $codePoint > 126) {
                        throw new \RuntimeException(sprintf(
                            'Embedded TrueType fonts currently support only basic Latin characters unless the composite-font path is used; unsupported character "%s" encountered.',
                            $character
                        ));
                    }
                }

                $singleByteCharacterCodes = array_values(array_unique(array_map(
                    static fn (string $character): int => mb_ord($character),
                    $characters,
                )));
                sort($singleByteCharacterCodes);
                $firstChar = $singleByteCharacterCodes === [] ? 32 : min($singleByteCharacterCodes);
                $lastChar = $singleByteCharacterCodes === [] ? 32 : max($singleByteCharacterCodes);

                $fontFileObjectId = $nextObjectId++;
                $fontDescriptorObjectId = $nextObjectId++;
                $widthsObjectId = $nextObjectId++;
                $codePointToGlyphId = array_filter(array_combine(
                    $singleByteCharacterCodes,
                    array_map(
                        fn (int $characterCode): ?int => $parsedTrueType->glyphIdForCodePoint($characterCode),
                        $singleByteCharacterCodes,
                    )
                ) ?: [], static fn (?int $glyphId): bool => $glyphId !== null && $glyphId > 0);
                $embeddedFont = $this->embeddedTrueTypeFontProgram(
                    $font,
                    $parsedTrueType,
                    $codePointToGlyphId,
                );
                $baseFontName = $this->embeddedTrueTypeBaseFontName($font, $embeddedFont);
                $fontProgram = $this->embeddedTrueTypeNamedFontProgram($embeddedFont, $baseFontName);

                $objects[$fontFileObjectId] = $this->buildStreamObject(
                    ['Length' => strlen($fontProgram)],
                    $fontProgram
                );
                $objects[$widthsObjectId] = '[' . implode(' ', $this->trueTypeWidths($parsedTrueType, $firstChar, $lastChar)) . ']';
                $objects[$fontDescriptorObjectId] = $this->serializePdfValue([
                    'Type' => 'FontDescriptor',
                    'FontName' => $baseFontName,
                    'Flags' => $parsedTrueType->descriptorFlags(),
                    'FontBBox' => $this->normalizeFontBox($parsedTrueType->fontBBox, $parsedTrueType->unitsPerEm),
                    'ItalicAngle' => $parsedTrueType->italicAngle,
                    'Ascent' => $this->normalizeFontMetric($parsedTrueType->ascent, $parsedTrueType->unitsPerEm),
                    'Descent' => $this->normalizeFontMetric($parsedTrueType->descent, $parsedTrueType->unitsPerEm),
                    'Leading' => $this->normalizeFontMetric($parsedTrueType->lineGap, $parsedTrueType->unitsPerEm),
                    'CapHeight' => $this->normalizeFontMetric($parsedTrueType->capHeight, $parsedTrueType->unitsPerEm),
                    'XHeight' => $this->normalizeFontMetric($parsedTrueType->xHeight, $parsedTrueType->unitsPerEm),
                    'AvgWidth' => $this->normalizeFontMetric($parsedTrueType->averageWidth(), $parsedTrueType->unitsPerEm),
                    'MaxWidth' => $this->normalizeFontMetric($parsedTrueType->maxWidth(), $parsedTrueType->unitsPerEm),
                    'MissingWidth' => $this->normalizeFontMetric($parsedTrueType->missingWidth(), $parsedTrueType->unitsPerEm),
                    'StemV' => $parsedTrueType->stemV(),
                    'FontFile2' => new PdfReference($fontFileObjectId, 0),
                ]);

                $fontDictionary = [
                    'Type' => 'Font',
                    'Subtype' => 'TrueType',
                    'BaseFont' => $baseFontName,
                    'FirstChar' => $firstChar,
                    'LastChar' => $lastChar,
                    'Widths' => new PdfReference($widthsObjectId, 0),
                    'FontDescriptor' => new PdfReference($fontDescriptorObjectId, 0),
                    'Encoding' => 'WinAnsiEncoding',
                ];

                if ($toUnicodeObjectId !== null) {
                    $fontDictionary['ToUnicode'] = new PdfReference($toUnicodeObjectId, 0);
                }

                $objects[$objectId] = $this->serializePdfValue($fontDictionary);
                $resourceMap[$key] = [
                    'key' => $key,
                    'resourceName' => $resourceName,
                    'objectId' => $objectId,
                    'font' => $font,
                ];

                continue;
            }

            if ($characters !== []) {
                $toUnicodeObjectId = $nextObjectId++;
                $cmap = $this->toUnicodeCMapBuilder()->build($characters);
                $objects[$toUnicodeObjectId] = $this->buildStreamObject(['Length' => strlen($cmap)], $cmap);
            }

            $fontDictionary = [
                'Type' => 'Font',
                'Subtype' => 'Type1',
                'BaseFont' => $font->baseFont,
            ];

            if ($toUnicodeObjectId !== null) {
                $fontDictionary['ToUnicode'] = new PdfReference($toUnicodeObjectId, 0);
            }

            $objects[$objectId] = $this->serializePdfValue($fontDictionary);
            $resourceMap[$key] = [
                'key' => $key,
                'resourceName' => $resourceName,
                'objectId' => $objectId,
                'font' => $font,
            ];
        }

        return [
            'objects' => $objects,
            'resourceMap' => $resourceMap,
            'textEncodingMap' => $textEncodingMap,
            'nextObjectId' => $nextObjectId,
        ];
    }

    /**
     * @param array<string, int> $characterToCid
     * @return array{objects: array<int, string>, fontObjectId: int, textEncoding: CompositeFontEncoding, nextObjectId: int}
     */
    private function buildCompositeTrueTypeFontObjects(
        int $nextObjectId,
        ResolvedFont $font,
        array $characterToCid,
        ParsedTrueTypeFont $parsedTrueType,
        ?int $toUnicodeObjectId,
    ): array {
        $objects = [];
        $fontObjectId = $nextObjectId++;
        $descendantFontObjectId = $nextObjectId++;
        $fontDescriptorObjectId = $nextObjectId++;
        $fontFileObjectId = $nextObjectId++;
        $cidToGidMapObjectId = $nextObjectId++;
        $cidSetObjectId = $nextObjectId++;
        $cidToGlyphMap = [];
        $maxCid = 0;

        foreach ($characterToCid as $characterKey => $cid) {
            $character = CharacterMap::character($characterKey);
            $codePoint = mb_ord($character);
            $glyphId = $parsedTrueType->glyphIdForCodePoint($codePoint);

            if ($glyphId === null || $glyphId === 0) {
                throw new \RuntimeException(sprintf(
                    'The embedded TrueType font does not contain a glyph for character "%s".',
                    $character
                ));
            }

            $cidToGlyphMap[$cid] = $glyphId;
            $maxCid = max($maxCid, $cid);
        }

        $embeddedFont = $this->embeddedCompositeTrueTypeFontProgram($font, $parsedTrueType, array_values($cidToGlyphMap));
        $baseFontName = $this->embeddedTrueTypeBaseFontName($font, $embeddedFont);
        $cidToGlyphMap = $this->remapCompositeGlyphMap($cidToGlyphMap, $embeddedFont);
        $fontProgram = $this->embeddedTrueTypeNamedFontProgram($embeddedFont, $baseFontName);
        $objects[$fontFileObjectId] = $this->buildStreamObject(
            ['Length' => strlen($fontProgram)],
            $fontProgram
        );
        $objects[$cidToGidMapObjectId] = $this->buildStreamObject(
            ['Length' => ($maxCid + 1) * 2],
            $this->buildCidToGidMap($cidToGlyphMap, $maxCid),
        );
        $cidSet = $this->buildCidSet(array_keys($cidToGlyphMap), $maxCid);
        $objects[$cidSetObjectId] = $this->buildStreamObject(
            ['Length' => strlen($cidSet)],
            $cidSet,
        );
        $objects[$fontDescriptorObjectId] = $this->serializePdfValue([
            'Type' => 'FontDescriptor',
            'FontName' => $baseFontName,
            'Flags' => $parsedTrueType->descriptorFlags(),
            'FontBBox' => $this->normalizeFontBox($parsedTrueType->fontBBox, $parsedTrueType->unitsPerEm),
            'ItalicAngle' => $parsedTrueType->italicAngle,
            'Ascent' => $this->normalizeFontMetric($parsedTrueType->ascent, $parsedTrueType->unitsPerEm),
            'Descent' => $this->normalizeFontMetric($parsedTrueType->descent, $parsedTrueType->unitsPerEm),
            'Leading' => $this->normalizeFontMetric($parsedTrueType->lineGap, $parsedTrueType->unitsPerEm),
            'CapHeight' => $this->normalizeFontMetric($parsedTrueType->capHeight, $parsedTrueType->unitsPerEm),
            'XHeight' => $this->normalizeFontMetric($parsedTrueType->xHeight, $parsedTrueType->unitsPerEm),
            'AvgWidth' => $this->normalizeFontMetric($parsedTrueType->averageWidth(), $parsedTrueType->unitsPerEm),
            'MaxWidth' => $this->normalizeFontMetric($parsedTrueType->maxWidth(), $parsedTrueType->unitsPerEm),
            'MissingWidth' => $this->normalizeFontMetric($parsedTrueType->missingWidth(), $parsedTrueType->unitsPerEm),
            'StemV' => $parsedTrueType->stemV(),
            'CIDSet' => new PdfReference($cidSetObjectId, 0),
            'FontFile2' => new PdfReference($fontFileObjectId, 0),
        ]);
        $objects[$descendantFontObjectId] = $this->serializePdfValue([
            'Type' => 'Font',
            'Subtype' => 'CIDFontType2',
            'BaseFont' => $baseFontName,
            'CIDSystemInfo' => [
                'Registry' => new PdfLiteralString('Adobe'),
                'Ordering' => new PdfLiteralString('Identity'),
                'Supplement' => 0,
            ],
            'FontDescriptor' => new PdfReference($fontDescriptorObjectId, 0),
            'DW' => 1000,
            'W' => $this->compositeFontWidths($characterToCid, $parsedTrueType),
            'CIDToGIDMap' => new PdfReference($cidToGidMapObjectId, 0),
        ]);

        $fontDictionary = [
            'Type' => 'Font',
            'Subtype' => 'Type0',
            'BaseFont' => $baseFontName,
            'Encoding' => 'Identity-H',
            'DescendantFonts' => [new PdfReference($descendantFontObjectId, 0)],
        ];

        if ($toUnicodeObjectId !== null) {
            $fontDictionary['ToUnicode'] = new PdfReference($toUnicodeObjectId, 0);
        }

        $objects[$fontObjectId] = $this->serializePdfValue($fontDictionary);

        return [
            'objects' => $objects,
            'fontObjectId' => $fontObjectId,
            'textEncoding' => new CompositeFontEncoding($characterToCid),
            'nextObjectId' => $nextObjectId,
        ];
    }

    /**
     * @return list<int>
     */
    private function trueTypeWidths(ParsedTrueTypeFont $parsedTrueType, int $firstChar, int $lastChar): array
    {
        $widths = [];

        for ($code = $firstChar; $code <= $lastChar; $code++) {
            $glyphId = $parsedTrueType->glyphIdForCodePoint($code) ?? 0;
            $widths[] = $this->normalizeFontMetric(
                $parsedTrueType->widthForGlyphId($glyphId),
                $parsedTrueType->unitsPerEm,
            );
        }

        return $widths;
    }

    /**
     * @param array<string, int> $characterToCid
     * @return list<int|array<int>>
     */
    private function compositeFontWidths(array $characterToCid, ParsedTrueTypeFont $parsedTrueType): array
    {
        $entries = [];

        foreach ($characterToCid as $characterKey => $cid) {
            $character = CharacterMap::character($characterKey);
            $codePoint = mb_ord($character);
            $glyphId = $parsedTrueType->glyphIdForCodePoint($codePoint);

            if ($glyphId === null || $glyphId === 0) {
                continue;
            }

            $entries[] = $cid;
            $entries[] = [$this->normalizeFontMetric($parsedTrueType->widthForGlyphId($glyphId), $parsedTrueType->unitsPerEm)];
        }

        return $entries;
    }

    /**
     * @param array<int, int> $cidToGlyphMap
     */
    private function buildCidToGidMap(array $cidToGlyphMap, int $maxCid): string
    {
        $bytes = str_repeat("\x00\x00", $maxCid + 1);

        foreach ($cidToGlyphMap as $cid => $glyphId) {
            $offset = $cid * 2;
            $bytes[$offset] = chr(($glyphId >> 8) & 0xFF);
            $bytes[$offset + 1] = chr($glyphId & 0xFF);
        }

        return $bytes;
    }

    /**
     * @param list<int> $cids
     */
    private function buildCidSet(array $cids, int $maxCid): string
    {
        $bytes = str_repeat("\x00", (int) ceil(($maxCid + 1) / 8));

        foreach ($cids as $cid) {
            $byteOffset = intdiv($cid, 8);
            $bit = 7 - ($cid % 8);
            $bytes[$byteOffset] = chr(ord($bytes[$byteOffset]) | (1 << $bit));
        }

        return $bytes;
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $fontBox
     * @return list<int>
     */
    private function normalizeFontBox(array $fontBox, int $unitsPerEm): array
    {
        return [
            $this->normalizeFontMetric($fontBox[0], $unitsPerEm),
            $this->normalizeFontMetric($fontBox[1], $unitsPerEm),
            $this->normalizeFontMetric($fontBox[2], $unitsPerEm),
            $this->normalizeFontMetric($fontBox[3], $unitsPerEm),
        ];
    }

    private function normalizeFontMetric(int $metric, int $unitsPerEm): int
    {
        return (int) round(($metric / max(1, $unitsPerEm)) * 1000);
    }

    /**
     * @param list<string> $characterKeys
     */
    private function requiresCompositeTrueTypeFont(array $characterKeys): bool
    {
        foreach ($characterKeys as $characterKey) {
            if (CharacterMap::sourceText($characterKey) !== CharacterMap::character($characterKey)) {
                return true;
            }

            $character = CharacterMap::character($characterKey);
            $codePoint = mb_ord($character);

            if ($codePoint < 32 || $codePoint > 126) {
                return true;
            }
        }

        return false;
    }

    private function toUnicodeCMapBuilder(): ToUnicodeCMapBuilder
    {
        return $this->toUnicodeCMapBuilder ??= new ToUnicodeCMapBuilder();
    }

    /**
     * @return array{objects: array<int, string>, resourceMap: array<string, array{key: string, resourceName: string, objectId: int, image: ImageXObject}>, nextObjectId: int}
     */
    /**
     * @param list<array{
     *     fonts: array<string, ResolvedFont>,
     *     glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>,
     *     images: array<string, \PdfToolkit\Image\ImagePlacement>
     * }> $pageResourceAnalyses
     */
    private function buildImageObjects(array $pageResourceAnalyses, int $nextObjectId): array
    {
        $images = [];

        foreach ($pageResourceAnalyses as $pageResourceAnalysis) {
            foreach ($pageResourceAnalysis['images'] as $key => $placement) {
                $images[$key] = $this->imageReader()->readPlacement($placement);
            }
        }

        ksort($images);
        $objects = [];
        $resourceMap = [];
        $resourceIndex = 1;

        foreach ($images as $key => $image) {
            $objectId = $nextObjectId++;
            $softMaskObjectId = null;
            $iccProfileObjectId = null;

            if ($image->softMask !== null) {
                $softMaskObjectId = $nextObjectId++;
                $objects[$softMaskObjectId] = $this->buildStreamObject($image->softMask->dictionary, $image->softMask->data);
            }

            if ($image->iccProfile !== null) {
                $iccProfileObjectId = $nextObjectId++;
                $objects[$iccProfileObjectId] = $this->buildStreamObject(
                    $image->iccProfile['dictionary'],
                    $image->iccProfile['data']
                );
            }

            $dictionary = $image->dictionary;

            if ($softMaskObjectId !== null) {
                $dictionary['SMask'] = new PdfReference($softMaskObjectId, 0);
            }

            if ($iccProfileObjectId !== null) {
                $dictionary['ColorSpace'] = ['ICCBased', new PdfReference($iccProfileObjectId, 0)];
            }

            $resourceName = 'PT_Im' . $resourceIndex++;
            $objects[$objectId] = $this->buildStreamObject($dictionary, $image->data);
            $resourceMap[$key] = [
                'key' => $key,
                'resourceName' => $resourceName,
                'objectId' => $objectId,
                'image' => $image,
            ];
        }

        return [
            'objects' => $objects,
            'resourceMap' => $resourceMap,
            'nextObjectId' => $nextObjectId,
        ];
    }

    /**
     * @param array{fonts: array<string, ResolvedFont>, glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>, images: array<string, \PdfToolkit\Image\ImagePlacement>} $pageResourceAnalysis
     * @param array<string, array{key: string, resourceName: string, objectId: int, font: ResolvedFont}> $resourceMap
     * @return array<int, array{key: string, resourceName: string, objectId: int, font: ResolvedFont}>
     */
    private function pageFontResources(array $pageResourceAnalysis, array $resourceMap): array
    {
        $resources = [];

        foreach (array_keys($pageResourceAnalysis['fonts']) as $key) {
            if (isset($resourceMap[$key])) {
                $resources[] = $resourceMap[$key];
            }
        }

        return $resources;
    }

    /**
     * @param array{fonts: array<string, ResolvedFont>, glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>, images: array<string, \PdfToolkit\Image\ImagePlacement>} $pageResourceAnalysis
     * @param array<string, array{key: string, resourceName: string, objectId: int, image: ImageXObject}> $resourceMap
     * @return array<int, array{key: string, resourceName: string, objectId: int, image: ImageXObject}>
     */
    private function pageImageResources(array $pageResourceAnalysis, array $resourceMap): array
    {
        $resources = [];

        foreach (array_keys($pageResourceAnalysis['images']) as $key) {
            if (isset($resourceMap[$key])) {
                $resources[] = $resourceMap[$key];
            }
        }

        return $resources;
    }

    /**
     * @param array<int, array{key: string, resourceName: string, objectId: int, font: ResolvedFont}> $pageFontResources
     * @return array<string, PdfReference>
     */
    private function fontResourceDictionary(array $pageFontResources): array
    {
        $fonts = [];

        foreach ($pageFontResources as $resource) {
            $fonts[$resource['resourceName']] = new PdfReference($resource['objectId'], 0);
        }

        return $fonts;
    }

    /**
     * @param array{fonts: array<string, ResolvedFont>, glyphs: array<string, \PdfToolkit\Text\UsedGlyphSet>, images: array<string, \PdfToolkit\Image\ImagePlacement>} $pageResourceAnalysis
     * @param array<string, CompositeFontEncoding> $textEncodingMap
     * @return array<int, array{key: string, encoding: CompositeFontEncoding}>
     */
    private function pageFontTextEncodings(array $pageResourceAnalysis, array $textEncodingMap): array
    {
        $encodings = [];

        foreach (array_keys($pageResourceAnalysis['fonts']) as $key) {
            if (isset($textEncodingMap[$key])) {
                $encodings[] = [
                    'key' => $key,
                    'encoding' => $textEncodingMap[$key],
                ];
            }
        }

        return $encodings;
    }

    /**
     * @param array<int, array{key: string, resourceName: string, objectId: int, image: ImageXObject}> $pageImageResources
     * @return array<string, PdfReference>
     */
    private function imageResourceDictionary(array $pageImageResources): array
    {
        $images = [];

        foreach ($pageImageResources as $resource) {
            $images[$resource['resourceName']] = new PdfReference($resource['objectId'], 0);
        }

        return $images;
    }

    private function imageReader(): ImageReader
    {
        return $this->imageReader ??= new ImageReader();
    }

    private function trueTypeFontParser(): TrueTypeFontParser
    {
        return $this->trueTypeFontParser ??= new TrueTypeFontParser();
    }

    /**
     * @param array<int, int> $codePointToGlyphId
     */
    private function embeddedTrueTypeFontProgram(
        ResolvedFont $font,
        ParsedTrueTypeFont $parsedTrueType,
        array $codePointToGlyphId,
    ): TrueTypeFontSubsetResult {
        $fontBytes = $this->trueTypeFontParser()->fontProgram($font->sourcePath, $font->faceIndex);

        if ($parsedTrueType->disallowsSubsetting()) {
            return new TrueTypeFontSubsetResult($fontBytes, false);
        }

        return $this->trueTypeFontSubsetter()->subsetDenseWithCmap($fontBytes, $codePointToGlyphId);
    }

    /**
     * @param list<int> $requiredGlyphIds
     */
    private function embeddedCompositeTrueTypeFontProgram(
        ResolvedFont $font,
        ParsedTrueTypeFont $parsedTrueType,
        array $requiredGlyphIds,
    ): TrueTypeFontSubsetResult {
        $fontBytes = $this->trueTypeFontParser()->fontProgram($font->sourcePath, $font->faceIndex);

        if ($parsedTrueType->disallowsSubsetting()) {
            return new TrueTypeFontSubsetResult($fontBytes, false);
        }

        return $this->trueTypeFontSubsetter()->subsetDense($fontBytes, $requiredGlyphIds);
    }

    private function embeddedTrueTypeBaseFontName(ResolvedFont $font, TrueTypeFontSubsetResult $embeddedFont): string
    {
        if (!$embeddedFont->subsetted) {
            return $font->baseFont;
        }

        return $this->subsetFontTag($font->baseFont) . '+' . $font->baseFont;
    }

    private function subsetFontTag(string $baseFont): string
    {
        $hash = sha1($baseFont, true);
        $tag = '';

        for ($index = 0; $index < 6; $index++) {
            $tag .= chr(65 + (ord($hash[$index]) % 26));
        }

        return $tag;
    }

    private function embeddedTrueTypeNamedFontProgram(TrueTypeFontSubsetResult $embeddedFont, string $baseFontName): string
    {
        if (!$embeddedFont->subsetted) {
            return $embeddedFont->fontProgram;
        }

        return $this->trueTypeFontSubsetter()->rewritePostScriptName($embeddedFont->fontProgram, $baseFontName);
    }

    /**
     * @param array<int, int> $cidToGlyphMap
     * @return array<int, int>
     */
    private function remapCompositeGlyphMap(array $cidToGlyphMap, TrueTypeFontSubsetResult $embeddedFont): array
    {
        if ($embeddedFont->glyphIdMap === []) {
            return $cidToGlyphMap;
        }

        $remapped = [];

        foreach ($cidToGlyphMap as $cid => $glyphId) {
            $remapped[$cid] = $embeddedFont->mappedGlyphId($glyphId) ?? $glyphId;
        }

        return $remapped;
    }

    private function trueTypeFontSubsetter(): TrueTypeFontSubsetter
    {
        return $this->trueTypeFontSubsetter ??= new TrueTypeFontSubsetter();
    }

    /**
     * @param array<int, string> $objects
     * @param array<int, string> $dependentObjects
     */
    private function copyImportedObjectGraph(
        array &$objects,
        int &$nextObjectId,
        int $rootObjectNumber,
        array $dependentObjects,
        ?callable $beforeRewrite = null,
        ?callable $afterRewrite = null,
        ?string $serializedRootValue = null,
    ): ?int {
        $objectMap = [];

        foreach (array_keys($dependentObjects) as $sourceObjectNumber) {
            $objectMap[$sourceObjectNumber] = $nextObjectId++;
        }

        foreach ($dependentObjects as $sourceObjectNumber => $serializedValue) {
            $preparedValue = $beforeRewrite === null ? $serializedValue : $beforeRewrite($serializedValue);
            $rewrittenValue = $this->rewriteIndirectReferences($preparedValue, $objectMap);
            $objects[$objectMap[$sourceObjectNumber]] = $afterRewrite === null ? $rewrittenValue : $afterRewrite($rewrittenValue);
        }

        if ($serializedRootValue !== null) {
            $rootObjectId = $nextObjectId++;
            $preparedRootValue = $beforeRewrite === null ? $serializedRootValue : $beforeRewrite($serializedRootValue);
            $rewrittenRootValue = $this->rewriteIndirectReferences($preparedRootValue, $objectMap);
            $objects[$rootObjectId] = $afterRewrite === null ? $rewrittenRootValue : $afterRewrite($rewrittenRootValue);

            return $rootObjectId;
        }

        return $objectMap[$rootObjectNumber] ?? null;
    }

    /**
     * @param array<int, int> $dependencyMap
     */
    private function rewriteIndirectReferences(string $serializedValue, array $dependencyMap): string
    {
        return preg_replace_callback(
            '/\b(\d+)\s+(\d+)\s+R\b/',
            static function (array $matches) use ($dependencyMap): string {
                $sourceObjectNumber = (int) $matches[1];

                if (!isset($dependencyMap[$sourceObjectNumber])) {
                    return $matches[0];
                }

                return sprintf('%d %s R', $dependencyMap[$sourceObjectNumber], $matches[2]);
            },
            $serializedValue
        ) ?? $serializedValue;
    }

    /**
     * @param array<int, int> $pageObjectMap
     */
    private static function prepareOutlineDestinations(string $serializedValue, array $pageObjectMap): string
    {
        $serializedValue = preg_replace_callback(
            '/(\/Dest\s*\[\s*)(\d+)\s+(\d+)\s+R\b/',
            static function (array $matches) use ($pageObjectMap): string {
                $sourcePageObjectNumber = (int) $matches[2];

                if (!isset($pageObjectMap[$sourcePageObjectNumber])) {
                    return $matches[0];
                }

                return sprintf('%s__PT_PAGE_REF_%d__', $matches[1], $sourcePageObjectNumber);
            },
            $serializedValue
        ) ?? $serializedValue;

        return preg_replace_callback(
            '/(\/D\s*\[\s*)(\d+)\s+(\d+)\s+R\b/',
            static function (array $matches) use ($pageObjectMap): string {
                $sourcePageObjectNumber = (int) $matches[2];

                if (!isset($pageObjectMap[$sourcePageObjectNumber])) {
                    return $matches[0];
                }

                return sprintf('%s__PT_PAGE_REF_%d__', $matches[1], $sourcePageObjectNumber);
            },
            $serializedValue
        ) ?? $serializedValue;
    }

    /**
     * @param array<int, int> $pageObjectMap
     */
    private static function finalizeOutlineDestinations(string $serializedValue, array $pageObjectMap): string
    {
        return preg_replace_callback(
            '/__PT_PAGE_REF_(\d+)__/',
            static function (array $matches) use ($pageObjectMap): string {
                $sourcePageObjectNumber = (int) $matches[1];

                if (!isset($pageObjectMap[$sourcePageObjectNumber])) {
                    return $matches[0];
                }

                return sprintf('%d 0 R', $pageObjectMap[$sourcePageObjectNumber]);
            },
            $serializedValue
        ) ?? $serializedValue;
    }

    /**
     * @param array<int, int> $dependencyMap
     * @param array<int, int> $pageObjectMap
     */
    private function remapPdfValue(mixed $value, array $dependencyMap, array $pageObjectMap = []): mixed
    {
        if ($value instanceof PdfReference) {
            if (isset($dependencyMap[$value->objectNumber])) {
                return new PdfReference($dependencyMap[$value->objectNumber], $value->generationNumber);
            }

            if (isset($pageObjectMap[$value->objectNumber])) {
                return new PdfReference($pageObjectMap[$value->objectNumber], $value->generationNumber);
            }

            return $value;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->remapPdfValue($item, $dependencyMap, $pageObjectMap);
            }

            return $result;
        }

        return $value;
    }

    /**
     * @param array<int, int> $dependencyMap
     * @param array<int, int> $pageObjectMap
     */
    private function remapAnnotationValue(mixed $value, array $dependencyMap, array $pageObjectMap): mixed
    {
        if ($value instanceof PdfReference) {
            return $this->remapPdfValue($value, $dependencyMap, $pageObjectMap);
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        $isGoToAction = false;

        if (isset($value['S'])) {
            $actionType = $value['S'] instanceof PdfName
                ? $value['S']->value
                : (is_string($value['S']) ? $value['S'] : null);
            $isGoToAction = $actionType === 'GoTo';
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && ($key === 'Dest' || ($isGoToAction && $key === 'D'))) {
                $result[$key] = $this->remapAnnotationDestination($item, $dependencyMap, $pageObjectMap);
                continue;
            }

            $result[$key] = $this->remapAnnotationValue($item, $dependencyMap, $pageObjectMap);
        }

        return $result;
    }

    /**
     * @param array<int, int> $dependencyMap
     * @param array<int, int> $pageObjectMap
     */
    private function remapAnnotationDestination(mixed $value, array $dependencyMap, array $pageObjectMap): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            return $this->remapAnnotationValue($value, $dependencyMap, $pageObjectMap);
        }

        $result = [];

        foreach ($value as $index => $item) {
            if ($index === 0 && $item instanceof PdfReference && isset($pageObjectMap[$item->objectNumber])) {
                $result[] = new PdfReference($pageObjectMap[$item->objectNumber], $item->generationNumber);
                continue;
            }

            $result[] = $this->remapAnnotationValue($item, $dependencyMap, $pageObjectMap);
        }

        return $result;
    }

    private function serializePdfValue(mixed $value): string
    {
        $this->serializer ??= new PdfValueSerializer();

        return $this->serializer->serialize($value);
    }
}
