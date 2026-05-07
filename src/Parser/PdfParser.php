<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Navigation\MarkInfo;
use PdfToolkit\Navigation\OpenAction;

final class PdfParser
{
    public function parseFile(string $path, ?string $password = null): ParsedPdfDocument
    {
        if (!is_file($path)) {
            throw new PdfException(sprintf('PDF file not found: %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new PdfException(sprintf('Unable to read PDF file: %s', $path));
        }

        return $this->parseString($contents, $password);
    }

    public function parseString(string $contents, ?string $password = null): ParsedPdfDocument
    {
        $version = $this->extractVersion($contents);
        [$offsets, $compressedObjects, $trailer, $warnings] = $this->parseCrossReferenceData($contents);
        $bootstrapRepository = new PdfObjectRepository($contents, $offsets, $compressedObjects);
        $securityHandler = $this->createSecurityHandler($trailer, $bootstrapRepository, $password);
        $encryption = $this->parseEncryption($trailer, $bootstrapRepository, $securityHandler, $password);
        $repository = new PdfObjectRepository($contents, $offsets, $compressedObjects, $securityHandler);
        $pages = (new PageTreeResolver())->resolvePages($trailer, $repository, $warnings);
        $acroForm = $this->parseAcroForm($trailer, $repository, $warnings);
        $outline = $this->parseOutline($trailer, $repository, $warnings);
        $nameTree = $this->parseNameTree($trailer, $repository, $warnings);
        $metadata = $this->parseMetadata($trailer, $repository, $warnings);
        $catalogMetadata = $this->parseCatalogMetadata($trailer, $repository, $warnings);
        $pageLabels = $this->parsePageLabels($trailer, $repository, $warnings);
        $viewerPreferences = $this->parseViewerPreferences($trailer, $repository, $warnings);
        $outputIntents = $this->parseOutputIntents($trailer, $repository, $warnings);
        $structTree = $this->parseStructTree($trailer, $repository, $warnings);
        $openAction = $this->parseOpenAction($trailer, $repository, $pages);
        $markInfo = $this->parseMarkInfo($trailer, $repository);
        [$pageLayout, $pageMode, $language, $uriBase] = $this->parseCatalogView($trailer, $repository);

        return new ParsedPdfDocument(
            version: $version,
            pages: $pages,
            trailer: $trailer,
            acroForm: $acroForm,
            outline: $outline,
            nameTree: $nameTree,
            metadata: $metadata,
            catalogMetadata: $catalogMetadata,
            pageLabels: $pageLabels,
            viewerPreferences: $viewerPreferences,
            outputIntents: $outputIntents,
            structTree: $structTree,
            encryption: $encryption,
            openAction: $openAction,
            markInfo: $markInfo,
            pageLayout: $pageLayout,
            pageMode: $pageMode,
            language: $language,
            uriBase: $uriBase,
            warnings: $warnings,
        );
    }

    private function extractVersion(string $contents): string
    {
        if (!preg_match('/%PDF-(1\.[0-8])/', $contents, $matches)) {
            throw new PdfException('Input does not appear to be a supported PDF 1.0 through 1.8 file.');
        }

        return $matches[1];
    }

    private function createSecurityHandler(
        array $trailer,
        PdfObjectRepository $repository,
        ?string $password,
    ): ?StandardSecurityHandler
    {
        $encrypt = $trailer['Encrypt'] ?? null;

        if ($encrypt === null) {
            return null;
        }

        $encryptObjectNumber = null;

        if ($encrypt instanceof PdfReference) {
            $encryptObjectNumber = $encrypt->objectNumber;
            $encrypt = $repository->resolve($encrypt);
        }

        if (!is_array($encrypt)) {
            throw new PdfException('Encrypted PDF has an invalid Encrypt dictionary.');
        }

        return StandardSecurityHandler::fromTrailer(
            $encrypt,
            $trailer,
            $password,
            $encryptObjectNumber ?? -1,
        );
    }

    private function parseEncryption(
        array $trailer,
        PdfObjectRepository $repository,
        ?StandardSecurityHandler $securityHandler = null,
        ?string $password = null,
    ): ?ParsedEncryption
    {
        $encrypt = $trailer['Encrypt'] ?? null;

        if ($encrypt === null) {
            return null;
        }

        if ($encrypt instanceof PdfReference) {
            $encrypt = $repository->resolve($encrypt);
        }

        if (!is_array($encrypt)) {
            throw new PdfException('Encrypted PDF has an invalid Encrypt dictionary.');
        }

        return StandardSecurityHandler::describeEncryptionWithAuthentication(
            $encrypt,
            $securityHandler?->authenticatedAs(),
            ($password ?? '') !== '',
        );
    }

    private function parseAcroForm(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedAcroForm
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['AcroForm'])) {
            return null;
        }

        $acroForm = $catalog['AcroForm'];

        if (!$acroForm instanceof PdfReference) {
            $warnings[] = 'Encountered inline AcroForm dictionary; inline AcroForm preservation is not implemented yet.';

            return null;
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($acroForm, $visited);

        return new ParsedAcroForm(
            objectNumber: $acroForm->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseOutline(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedOutline
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['Outlines'])) {
            return null;
        }

        $outline = $catalog['Outlines'];

        if (!$outline instanceof PdfReference) {
            $warnings[] = 'Encountered inline Outlines dictionary; inline outline preservation is not implemented yet.';

            return null;
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($outline, $visited);

        return new ParsedOutline(
            objectNumber: $outline->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseNameTree(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedNameTree
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['Names'])) {
            return null;
        }

        $names = $catalog['Names'];

        if (!$names instanceof PdfReference) {
            $warnings[] = 'Encountered inline Names dictionary; inline name-tree preservation is not implemented yet.';

            return null;
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($names, $visited);

        return new ParsedNameTree(
            objectNumber: $names->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseMetadata(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedMetadata
    {
        $info = $trailer['Info'] ?? null;

        if ($info === null) {
            return null;
        }

        if ($info instanceof PdfReference) {
            $info = $repository->resolve($info);
        }

        if (!is_array($info)) {
            $warnings[] = 'Encountered non-dictionary trailer Info value; document metadata import skipped.';

            return null;
        }

        return new ParsedMetadata(
            title: $this->optionalString($info['Title'] ?? null),
            author: $this->optionalString($info['Author'] ?? null),
            subject: $this->optionalString($info['Subject'] ?? null),
            keywords: $this->keywords($this->optionalString($info['Keywords'] ?? null)),
        );
    }

    private function parseCatalogMetadata(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedCatalogMetadata
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['Metadata'])) {
            return null;
        }

        $metadata = $catalog['Metadata'];

        if (!$metadata instanceof PdfReference) {
            if (!$metadata instanceof PdfStream && !is_array($metadata)) {
                $warnings[] = 'Encountered non-stream catalog Metadata value; catalog XMP metadata preservation skipped.';

                return null;
            }

            [$dependentObjects, $serializedValue] = $this->preserveInlineCatalogValue($metadata, $repository);

            return new ParsedCatalogMetadata(
                objectNumber: -1,
                dependentObjects: $dependentObjects,
                serializedValue: $serializedValue,
            );
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($metadata, $visited);

        return new ParsedCatalogMetadata(
            objectNumber: $metadata->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parsePageLabels(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedPageLabels
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['PageLabels'])) {
            return null;
        }

        $pageLabels = $catalog['PageLabels'];

        if (!$pageLabels instanceof PdfReference) {
            if (!is_array($pageLabels)) {
                $warnings[] = 'Encountered non-dictionary PageLabels value; page-label preservation skipped.';

                return null;
            }

            [$dependentObjects, $serializedValue] = $this->preserveInlineCatalogValue($pageLabels, $repository);

            return new ParsedPageLabels(
                objectNumber: -1,
                dependentObjects: $dependentObjects,
                serializedValue: $serializedValue,
            );
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($pageLabels, $visited);

        return new ParsedPageLabels(
            objectNumber: $pageLabels->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseViewerPreferences(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedViewerPreferences
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['ViewerPreferences'])) {
            return null;
        }

        $viewerPreferences = $catalog['ViewerPreferences'];

        if (!$viewerPreferences instanceof PdfReference) {
            if (!is_array($viewerPreferences)) {
                $warnings[] = 'Encountered non-dictionary ViewerPreferences value; viewer-preference preservation skipped.';

                return null;
            }

            [$dependentObjects, $serializedValue] = $this->preserveInlineCatalogValue($viewerPreferences, $repository);

            return new ParsedViewerPreferences(
                objectNumber: -1,
                dependentObjects: $dependentObjects,
                serializedValue: $serializedValue,
            );
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($viewerPreferences, $visited);

        return new ParsedViewerPreferences(
            objectNumber: $viewerPreferences->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseOutputIntents(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedOutputIntents
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['OutputIntents'])) {
            return null;
        }

        $outputIntents = $catalog['OutputIntents'];

        if (!$outputIntents instanceof PdfReference) {
            if (!is_array($outputIntents) || !array_is_list($outputIntents)) {
                $warnings[] = 'Encountered non-array OutputIntents value; output-intent preservation skipped.';

                return null;
            }

            [$dependentObjects, $serializedValue] = $this->preserveInlineCatalogValue($outputIntents, $repository);

            return new ParsedOutputIntents(
                objectNumber: -1,
                dependentObjects: $dependentObjects,
                serializedValue: $serializedValue,
            );
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($outputIntents, $visited);

        return new ParsedOutputIntents(
            objectNumber: $outputIntents->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    private function parseStructTree(array $trailer, PdfObjectRepository $repository, array &$warnings): ?ParsedStructTree
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !isset($catalog['StructTreeRoot'])) {
            return null;
        }

        $structTree = $catalog['StructTreeRoot'];

        if (!$structTree instanceof PdfReference) {
            if (!is_array($structTree)) {
                $warnings[] = 'Encountered non-dictionary StructTreeRoot value; structure-tree preservation skipped.';

                return null;
            }

            [$dependentObjects, $serializedValue] = $this->preserveInlineCatalogValue($structTree, $repository);

            return new ParsedStructTree(
                objectNumber: -1,
                dependentObjects: $dependentObjects,
                serializedValue: $serializedValue,
            );
        }

        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($structTree, $visited);

        return new ParsedStructTree(
            objectNumber: $structTree->objectNumber,
            dependentObjects: $dependentObjects,
        );
    }

    /**
     * @return array{0: array<int, string>, 1: string}
     */
    private function preserveInlineCatalogValue(mixed $value, PdfObjectRepository $repository): array
    {
        $visited = [];
        $dependentObjects = $repository->collectDependentObjects($value, $visited);

        return [
            $dependentObjects,
            (new PdfValueSerializer())->serialize($value),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function parseCatalogView(array $trailer, PdfObjectRepository $repository): array
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return [null, null, null, null];
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog)) {
            return [null, null, null, null];
        }

        $uriBase = null;
        $uri = $catalog['URI'] ?? null;

        if ($uri instanceof PdfReference) {
            $uri = $repository->resolve($uri);
        }

        if (is_array($uri)) {
            $uriBase = $this->optionalString($uri['Base'] ?? null);
        }

        return [
            is_string($catalog['PageLayout'] ?? null) ? $catalog['PageLayout'] : null,
            is_string($catalog['PageMode'] ?? null) ? $catalog['PageMode'] : null,
            $this->optionalString($catalog['Lang'] ?? null),
            $uriBase,
        ];
    }

    /**
     * @param list<ParsedPage> $pages
     */
    private function parseOpenAction(array $trailer, PdfObjectRepository $repository, array $pages): ?OpenAction
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !array_key_exists('OpenAction', $catalog)) {
            return null;
        }

        return $this->toOpenAction($catalog['OpenAction'], $repository, $pages);
    }

    private function parseMarkInfo(array $trailer, PdfObjectRepository $repository): ?MarkInfo
    {
        $root = $trailer['Root'] ?? null;

        if (!$root instanceof PdfReference) {
            return null;
        }

        $catalog = $repository->resolve($root);

        if (!is_array($catalog) || !array_key_exists('MarkInfo', $catalog)) {
            return null;
        }

        $markInfo = $catalog['MarkInfo'];

        if ($markInfo instanceof PdfReference) {
            $markInfo = $repository->resolve($markInfo);
        }

        if (!is_array($markInfo)) {
            return null;
        }

        if (!is_bool($markInfo['Marked'] ?? null)) {
            return null;
        }

        return new MarkInfo(
            marked: $markInfo['Marked'],
            userProperties: is_bool($markInfo['UserProperties'] ?? null) ? $markInfo['UserProperties'] : null,
            suspects: is_bool($markInfo['Suspects'] ?? null) ? $markInfo['Suspects'] : null,
        );
    }

    /**
     * @param list<ParsedPage> $pages
     */
    private function toOpenAction(mixed $value, PdfObjectRepository $repository, array $pages): ?OpenAction
    {
        if ($value instanceof PdfReference) {
            $value = $repository->resolve($value);
        }

        $stringValue = $this->optionalString($value);

        if ($stringValue !== null) {
            return OpenAction::toNamedDestination($stringValue);
        }

        if (is_array($value) && array_is_list($value)) {
            return $this->destinationArrayToOpenAction($value, $pages);
        }

        if (is_array($value) && ($value['S'] ?? null) === 'GoTo' && array_key_exists('D', $value)) {
            return $this->toOpenAction($value['D'], $repository, $pages);
        }

        return null;
    }

    /**
     * @param list<mixed> $destination
     * @param list<ParsedPage> $pages
     */
    private function destinationArrayToOpenAction(array $destination, array $pages): ?OpenAction
    {
        $pageReference = $destination[0] ?? null;

        if (!$pageReference instanceof PdfReference) {
            return null;
        }

        $pageNumber = $this->pageNumberForReference($pageReference, $pages);

        if ($pageNumber === null) {
            return null;
        }

        $pageHeight = $pages[$pageNumber - 1]->height ?? null;
        $top = $this->optionalFloat($destination[3] ?? null);

        if ($top !== null && $pageHeight !== null) {
            $top = $pageHeight - $top;
        }

        return OpenAction::toPage(
            $pageNumber,
            $this->optionalFloat($destination[2] ?? null),
            $top,
            $this->optionalFloat($destination[4] ?? null),
        );
    }

    /**
     * @param list<ParsedPage> $pages
     */
    private function pageNumberForReference(PdfReference $reference, array $pages): ?int
    {
        foreach ($pages as $index => $page) {
            if ($page->objectNumber === $reference->objectNumber) {
                return $index + 1;
            }
        }

        return null;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value instanceof PdfLiteralString) {
            return $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function optionalFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function keywords(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $keyword): string => trim($keyword), explode(',', $value)),
            static fn (string $keyword): bool => $keyword !== ''
        ));
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, array{objectStreamNumber: int, index: int}>, 2: array<string, mixed>, 3: list<string>}
     */
    private function parseCrossReferenceData(string $contents): array
    {
        $warnings = [];
        $xrefOffset = $this->extractStartXrefOffset($contents);
        $offsets = [];
        $compressedObjects = [];
        $trailer = [];
        $visitedXrefOffsets = [];

        $this->readCrossReferenceSection($contents, $xrefOffset, $offsets, $compressedObjects, $trailer, $warnings, $visitedXrefOffsets);

        return [$offsets, $compressedObjects, $trailer, $warnings];
    }

    /**
     * @param array<int, int> $offsets
     * @param array<int, array{objectStreamNumber: int, index: int}> $compressedObjects
     * @param array<string, mixed> $trailer
     * @param list<string> $warnings
     * @param array<int, true> $visitedXrefOffsets
     */
    private function readCrossReferenceSection(
        string $contents,
        int $xrefOffset,
        array &$offsets,
        array &$compressedObjects,
        array &$trailer,
        array &$warnings,
        array &$visitedXrefOffsets,
    ): void {
        if (isset($visitedXrefOffsets[$xrefOffset])) {
            return;
        }

        $visitedXrefOffsets[$xrefOffset] = true;

        if (substr($contents, $xrefOffset, 4) === 'xref') {
            $this->readClassicXrefSection($contents, $xrefOffset, $offsets, $compressedObjects, $trailer, $warnings, $visitedXrefOffsets);

            return;
        }

        $this->readXrefStreamSection($contents, $xrefOffset, $offsets, $compressedObjects, $trailer, $warnings, $visitedXrefOffsets);
    }

    /**
     * @param array<int, int> $offsets
     * @param array<int, array{objectStreamNumber: int, index: int}> $compressedObjects
     * @param array<string, mixed> $trailer
     * @param list<string> $warnings
     * @param array<int, true> $visitedXrefOffsets
     */
    private function readClassicXrefSection(
        string $contents,
        int $xrefOffset,
        array &$offsets,
        array &$compressedObjects,
        array &$trailer,
        array &$warnings,
        array &$visitedXrefOffsets,
    ): void {
        $cursor = $xrefOffset + 4;
        $parser = new PdfValueParser($contents);

        while (true) {
            $parser->skipWhitespaceAndComments($cursor);

            if (substr($contents, $cursor, 7) === 'trailer') {
                $cursor += 7;
                $currentTrailer = $parser->parseValue($cursor);

                if (!is_array($currentTrailer)) {
                    throw new PdfException('Invalid trailer dictionary.');
                }

                $previousTrailer = [];

                if (isset($currentTrailer['XRefStm']) && is_int($currentTrailer['XRefStm'])) {
                    $xrefStreamTrailer = [];
                    $this->readCrossReferenceSection(
                        $contents,
                        $currentTrailer['XRefStm'],
                        $offsets,
                        $compressedObjects,
                        $xrefStreamTrailer,
                        $warnings,
                        $visitedXrefOffsets,
                    );
                    $previousTrailer = $xrefStreamTrailer + $previousTrailer;
                }

                if (isset($currentTrailer['Prev']) && is_int($currentTrailer['Prev'])) {
                    $this->readCrossReferenceSection(
                        $contents,
                        $currentTrailer['Prev'],
                        $offsets,
                        $compressedObjects,
                        $previousTrailer,
                        $warnings,
                        $visitedXrefOffsets,
                    );
                }

                $trailer = $currentTrailer + $previousTrailer;

                return;
            }

            if (!preg_match('/\G(\d+)\s+(\d+)\s*/As', $contents, $sectionMatches, 0, $cursor)) {
                throw new PdfException('Invalid xref section header.');
            }

            $cursor += strlen($sectionMatches[0]);
            $startObject = (int) $sectionMatches[1];
            $count = (int) $sectionMatches[2];

            for ($i = 0; $i < $count; $i++) {
                if (!preg_match('/\G(\d{10})\s(\d{5})\s([fn])\s*(?:\r\n|\n|\r)?/As', $contents, $entryMatches, 0, $cursor)) {
                    throw new PdfException('Invalid xref entry.');
                }

                $cursor += strlen($entryMatches[0]);

                if ($entryMatches[3] === 'n') {
                    $offsets[$startObject + $i] = (int) $entryMatches[1];
                }
            }
        }
    }

    /**
     * @param array<int, int> $offsets
     * @param array<int, array{objectStreamNumber: int, index: int}> $compressedObjects
     * @param array<string, mixed> $trailer
     * @param list<string> $warnings
     * @param array<int, true> $visitedXrefOffsets
     */
    private function readXrefStreamSection(
        string $contents,
        int $xrefOffset,
        array &$offsets,
        array &$compressedObjects,
        array &$trailer,
        array &$warnings,
        array &$visitedXrefOffsets,
    ): void {
        if (!preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/As', $contents, $matches, 0, $xrefOffset)) {
            throw new PdfException('Invalid xref stream object header.');
        }

        $value = (new PdfObjectRepository(
            $contents,
            [(int) $matches[1] => $xrefOffset],
        ))->get(new PdfReference((int) $matches[1], (int) $matches[2]))->value;

        if (!$value instanceof PdfStream) {
            throw new PdfException('Xref stream offset did not resolve to a stream object.');
        }

        $dictionary = $value->dictionary;

        if (($dictionary['Type'] ?? null) !== 'XRef') {
            throw new PdfException('Indirect object at startxref is not an /XRef stream.');
        }

        $decoded = $this->decodeXrefStream($value);
        $this->readXrefStreamEntries($decoded, $dictionary, $offsets, $compressedObjects);
        $previousTrailer = [];

        if (isset($dictionary['Prev']) && is_int($dictionary['Prev'])) {
            $this->readCrossReferenceSection(
                $contents,
                $dictionary['Prev'],
                $offsets,
                $compressedObjects,
                $previousTrailer,
                $warnings,
                $visitedXrefOffsets,
            );
        }

        $trailer = $dictionary + $previousTrailer;
    }

    /**
     * @param array<string, mixed> $dictionary
     * @param array<int, int> $offsets
     * @param array<int, array{objectStreamNumber: int, index: int}> $compressedObjects
     */
    private function readXrefStreamEntries(
        string $decoded,
        array $dictionary,
        array &$offsets,
        array &$compressedObjects,
    ): void {
        $widths = $dictionary['W'] ?? null;

        if (!is_array($widths) || count($widths) !== 3) {
            throw new PdfException('Xref stream is missing a valid W entry.');
        }

        $index = $dictionary['Index'] ?? [0, $dictionary['Size'] ?? 0];

        if (!is_array($index)) {
            throw new PdfException('Xref stream has an invalid Index entry.');
        }

        $entryLength = array_sum(array_map(static fn (mixed $value): int => (int) $value, $widths));
        $cursor = 0;

        for ($i = 0; $i < count($index); $i += 2) {
            $startObject = (int) $index[$i];
            $count = (int) ($index[$i + 1] ?? 0);

            for ($j = 0; $j < $count; $j++) {
                $type = $this->readXrefField($decoded, $cursor, (int) $widths[0], 1);
                $field2 = $this->readXrefField($decoded, $cursor, (int) $widths[1], 0);
                $field3 = $this->readXrefField($decoded, $cursor, (int) $widths[2], 0);
                $objectNumber = $startObject + $j;

                if ($type === 1) {
                    $offsets[$objectNumber] = $field2;
                    continue;
                }

                if ($type === 2) {
                    $compressedObjects[$objectNumber] = [
                        'objectStreamNumber' => $field2,
                        'index' => $field3,
                    ];
                }
            }
        }

        if ($cursor > strlen($decoded) + $entryLength) {
            throw new PdfException('Xref stream parsing overran decoded data.');
        }
    }

    private function readXrefField(string $decoded, int &$cursor, int $width, int $default): int
    {
        if ($width === 0) {
            return $default;
        }

        $slice = substr($decoded, $cursor, $width);

        if ($slice === false || strlen($slice) !== $width) {
            throw new PdfException('Unexpected end of xref stream data.');
        }

        $cursor += $width;
        $value = 0;

        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($slice[$i]);
        }

        return $value;
    }

    private function decodeXrefStream(\PdfToolkit\Parser\PdfStream $stream): string
    {
        return (new PdfObjectRepository($stream->contents, []))->decodeStream($stream);
    }

    private function extractStartXrefOffset(string $contents): int
    {
        if (!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/s', $contents, $matches)) {
            throw new PdfException('Unable to locate startxref marker.');
        }

        return (int) $matches[1];
    }
}
