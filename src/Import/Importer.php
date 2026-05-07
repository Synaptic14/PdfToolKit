<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\DocumentMetadata;
use PdfToolkit\Core\ImportedAcroFormSource;
use PdfToolkit\Core\ImportedCatalogMetadataSource;
use PdfToolkit\Core\ImportedContentStream;
use PdfToolkit\Core\ImportedContentOperation;
use PdfToolkit\Core\ImportedNameTreeSource;
use PdfToolkit\Core\ImportedOutlineSource;
use PdfToolkit\Core\ImportedPageLabelsSource;
use PdfToolkit\Core\ImportedPageSource;
use PdfToolkit\Core\ImportedOutputIntentsSource;
use PdfToolkit\Core\ImportedStructTreeSource;
use PdfToolkit\Core\ImportedViewerPreferencesSource;
use PdfToolkit\Core\Page;
use PdfToolkit\Parser\PdfParser;

final class Importer
{
    public function __construct(
        private readonly PdfParser $parser = new PdfParser(),
    ) {
    }

    public function load(string $path, ?string $password = null): ImportedDocument
    {
        $parsed = $this->parser->parseFile($path, $password);

        return $this->fromParsedDocument($parsed);
    }

    public function loadString(string $contents, ?string $password = null): ImportedDocument
    {
        $parsed = $this->parser->parseString($contents, $password);

        return $this->fromParsedDocument($parsed);
    }

    private function fromParsedDocument(\PdfToolkit\Parser\ParsedPdfDocument $parsed): ImportedDocument
    {
        return new ImportedDocument(
            document: $this->toDocument($parsed),
            report: new ImportReport(
                version: $parsed->version(),
                pageCount: $parsed->pageCount(),
                warnings: $parsed->warnings(),
                security: $parsed->encryption() === null
                    ? null
                    : new ImportSecurityInfo(
                        filter: $parsed->encryption()->filter(),
                        version: $parsed->encryption()->version(),
                        revision: $parsed->encryption()->revision(),
                        keyLengthBits: $parsed->encryption()->keyLengthBits(),
                        permissions: $parsed->encryption()->permissions(),
                        authenticatedAs: $parsed->encryption()->authenticatedAs(),
                        openedWithPassword: $parsed->encryption()->openedWithPassword(),
                        cryptFilterNames: $parsed->encryption()->cryptFilterNames(),
                        cryptFilters: $parsed->encryption()->cryptFilters(),
                        cryptFilterAuthEvents: $parsed->encryption()->cryptFilterAuthEvents(),
                        cryptFilterKeyLengthBits: $parsed->encryption()->cryptFilterKeyLengthBits(),
                        embeddedFileFilterName: $parsed->encryption()->embeddedFileFilterName(),
                        stringFilterName: $parsed->encryption()->stringFilterName(),
                        streamFilterName: $parsed->encryption()->streamFilterName(),
                        embeddedFileMethod: $parsed->encryption()->embeddedFileMethod(),
                        stringMethod: $parsed->encryption()->stringMethod(),
                        streamMethod: $parsed->encryption()->streamMethod(),
                        encryptMetadata: $parsed->encryption()->encryptMetadata(),
                    ),
            ),
        );
    }

    private function toDocument(\PdfToolkit\Parser\ParsedPdfDocument $parsed): Document
    {
        $document = new Document();

        if ($parsed->metadata() !== null) {
            $document->setMetadata(new DocumentMetadata(
                title: $parsed->metadata()->title,
                author: $parsed->metadata()->author,
                subject: $parsed->metadata()->subject,
                keywords: $parsed->metadata()->keywords,
            ));
        }

        if ($parsed->acroForm() !== null) {
            $document->setImportedAcroFormSource(new ImportedAcroFormSource(
                objectNumber: $parsed->acroForm()->objectNumber,
                dependentObjects: $parsed->acroForm()->dependentObjects,
            ));
        }

        if ($parsed->outline() !== null) {
            $document->setImportedOutlineSource(new ImportedOutlineSource(
                objectNumber: $parsed->outline()->objectNumber,
                dependentObjects: $parsed->outline()->dependentObjects,
            ));
        }

        if ($parsed->nameTree() !== null) {
            $document->setImportedNameTreeSource(new ImportedNameTreeSource(
                objectNumber: $parsed->nameTree()->objectNumber,
                dependentObjects: $parsed->nameTree()->dependentObjects,
            ));
        }

        if ($parsed->catalogMetadata() !== null) {
            $document->setImportedCatalogMetadataSource(new ImportedCatalogMetadataSource(
                objectNumber: $parsed->catalogMetadata()->objectNumber,
                dependentObjects: $parsed->catalogMetadata()->dependentObjects,
                serializedValue: $parsed->catalogMetadata()->serializedValue,
            ));
        }

        if ($parsed->pageLabels() !== null) {
            $document->setImportedPageLabelsSource(new ImportedPageLabelsSource(
                objectNumber: $parsed->pageLabels()->objectNumber,
                dependentObjects: $parsed->pageLabels()->dependentObjects,
                serializedValue: $parsed->pageLabels()->serializedValue,
            ));
        }

        if ($parsed->viewerPreferences() !== null) {
            $document->setImportedViewerPreferencesSource(new ImportedViewerPreferencesSource(
                objectNumber: $parsed->viewerPreferences()->objectNumber,
                dependentObjects: $parsed->viewerPreferences()->dependentObjects,
                serializedValue: $parsed->viewerPreferences()->serializedValue,
            ));
        }

        if ($parsed->outputIntents() !== null) {
            $document->setImportedOutputIntentsSource(new ImportedOutputIntentsSource(
                objectNumber: $parsed->outputIntents()->objectNumber,
                dependentObjects: $parsed->outputIntents()->dependentObjects,
                serializedValue: $parsed->outputIntents()->serializedValue,
            ));
        }

        if ($parsed->structTree() !== null) {
            $document->setImportedStructTreeSource(new ImportedStructTreeSource(
                objectNumber: $parsed->structTree()->objectNumber,
                dependentObjects: $parsed->structTree()->dependentObjects,
                serializedValue: $parsed->structTree()->serializedValue,
            ));
        }

        $document->setOpenAction($parsed->openAction());
        $document->setMarkInfo($parsed->markInfo());
        $document->setPageLayout($parsed->pageLayout());
        $document->setPageMode($parsed->pageMode());
        $document->setLanguage($parsed->language());
        $document->setUriBase($parsed->uriBase());

        foreach ($parsed->pages() as $parsedPage) {
            $page = new Page($parsedPage->width, $parsedPage->height, $parsedPage->rotation, $parsedPage->pageBoxes);
            $page->setImportedSource(new ImportedPageSource(
                objectNumber: $parsedPage->objectNumber,
                pageDictionary: $parsedPage->dictionary,
                resourceDictionary: $parsedPage->resources,
                contentStreams: array_map(
                    static fn (\PdfToolkit\Parser\ParsedContentStream $stream): ImportedContentStream => new ImportedContentStream(
                        contents: $stream->contents,
                        dictionary: $stream->dictionary,
                        warnings: $stream->warnings,
                        operations: array_map(
                            static fn (\PdfToolkit\Parser\ParsedContentOperation $operation): ImportedContentOperation => new ImportedContentOperation(
                                operator: $operation->operator,
                                operands: $operation->operands,
                            ),
                            $stream->operations
                        ),
                    ),
                    $parsedPage->contentStreams
                ),
                dependentObjects: $parsedPage->dependentObjects,
                warnings: $parsedPage->warnings,
            ));
            $document->addPage($page);
        }

        return $document;
    }
}
