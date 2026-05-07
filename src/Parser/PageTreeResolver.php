<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;

final class PageTreeResolver
{
    /**
     * @return list<ParsedPage>
     */
    public function resolvePages(
        array $trailer,
        PdfObjectRepository $repository,
        array &$warnings = [],
    ): array {
        $catalog = $this->resolveDictionaryReference($trailer['Root'] ?? null, $repository, 'trailer Root');
        $pagesRoot = $this->resolveDictionaryReference($catalog['Pages'] ?? null, $repository, 'catalog Pages');

        return $this->walkPagesNode($pagesRoot, $repository, $warnings, []);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $inherited
     * @param list<string> $warnings
     * @return list<ParsedPage>
     */
    private function walkPagesNode(
        array $node,
        PdfObjectRepository $repository,
        array &$warnings,
        array $inherited,
    ): array {
        $type = $node['Type'] ?? null;
        $mergedInherited = $inherited;

        foreach (['MediaBox', 'CropBox', 'BleedBox', 'TrimBox', 'ArtBox', 'Resources', 'Rotate'] as $key) {
            if (array_key_exists($key, $node)) {
                $mergedInherited[$key] = $node[$key];
            }
        }

        if ($type === 'Page') {
            return [$this->buildPage($node, $repository, $warnings, $mergedInherited)];
        }

        if ($type !== 'Pages') {
            throw new PdfException('Unexpected page tree node type.');
        }

        $kids = $this->resolveArray($node['Kids'] ?? null, $repository);
        $pages = [];

        foreach ($kids as $kid) {
            $kidNode = $this->resolveDictionaryReference($kid, $repository, 'page tree child');

            foreach ($this->walkPagesNode($kidNode, $repository, $warnings, $mergedInherited) as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $inherited
     */
    private function buildPage(
        array $node,
        PdfObjectRepository $repository,
        array &$warnings,
        array $inherited,
    ): ParsedPage {
        $mediaBoxValue = $node['MediaBox'] ?? $inherited['MediaBox'] ?? null;

        if ($mediaBoxValue === null) {
            $warnings[] = 'Encountered a page without a MediaBox. Falling back to A4.';

            return new ParsedPage(
                objectNumber: $this->extractObjectNumber($node),
                width: 595.0,
                height: 842.0,
                rotation: $this->rotation($node['Rotate'] ?? $inherited['Rotate'] ?? null),
                dictionary: $node,
                pageBoxes: $this->pageBoxes($node, $inherited, $repository),
            );
        }

        $mediaBox = $this->resolveArray($mediaBoxValue, $repository);

        if (count($mediaBox) !== 4) {
            throw new PdfException('Invalid MediaBox entry encountered.');
        }

        $width = (float) $mediaBox[2] - (float) $mediaBox[0];
        $height = (float) $mediaBox[3] - (float) $mediaBox[1];
        $resources = $this->resolveOptionalDictionary($node['Resources'] ?? $inherited['Resources'] ?? null, $repository);
        $contentWarnings = [];
        $contentStreams = $this->resolveContentStreams($node['Contents'] ?? null, $repository, $contentWarnings);
        $visited = [];
        $dependentObjects = $resources !== null ? $repository->collectDependentObjects($resources, $visited) : [];

        if (isset($node['Annots'])) {
            foreach ($repository->collectDependentObjects($node['Annots'], $visited) as $objectNumber => $serializedValue) {
                $dependentObjects[$objectNumber] = $serializedValue;
            }
        }

        $warnings = [...$warnings, ...$contentWarnings];

        return new ParsedPage(
            objectNumber: $this->extractObjectNumber($node),
            width: $width,
            height: $height,
            rotation: $this->rotation($node['Rotate'] ?? $inherited['Rotate'] ?? null),
            dictionary: $node,
            pageBoxes: $this->pageBoxes($node, $inherited, $repository),
            resources: $resources,
            contentStreams: $contentStreams,
            dependentObjects: $dependentObjects,
            warnings: $contentWarnings,
        );
    }

    private function resolveDictionaryReference(mixed $value, PdfObjectRepository $repository, string $context): array
    {
        if (!$value instanceof PdfReference) {
            throw new PdfException(sprintf('Expected %s to be an indirect reference.', $context));
        }

        $resolved = $repository->resolve($value);

        if (!is_array($resolved)) {
            throw new PdfException(sprintf('Expected %s to resolve to a dictionary.', $context));
        }

        $resolved['__objectNumber'] = $value->objectNumber;

        return $resolved;
    }

    private function rotation(mixed $value): int
    {
        if (!is_int($value) && !is_float($value)) {
            return 0;
        }

        $rotation = (int) $value;

        return in_array($rotation, [0, 90, 180, 270], true) ? $rotation : 0;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $inherited
     * @return array<string, list<float>>
     */
    private function pageBoxes(array $node, array $inherited, PdfObjectRepository $repository): array
    {
        $boxes = [];

        foreach (['CropBox', 'BleedBox', 'TrimBox', 'ArtBox'] as $key) {
            $box = $this->optionalBox($node[$key] ?? $inherited[$key] ?? null, $repository);

            if ($box !== null) {
                $boxes[$key] = $box;
            }
        }

        return $boxes;
    }

    /**
     * @return list<float>|null
     */
    private function optionalBox(mixed $value, PdfObjectRepository $repository): ?array
    {
        if ($value === null) {
            return null;
        }

        $box = $this->resolveArray($value, $repository);

        if (count($box) !== 4) {
            return null;
        }

        return [
            (float) $box[0],
            (float) $box[1],
            (float) $box[2],
            (float) $box[3],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveOptionalDictionary(mixed $value, PdfObjectRepository $repository): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PdfReference) {
            $value = $repository->resolve($value);
        }

        if (!is_array($value)) {
            throw new PdfException('Expected Resources to resolve to a dictionary.');
        }

        return $value;
    }

    /**
     * @param list<string> $warnings
     * @return list<ParsedContentStream>
     */
    private function resolveContentStreams(mixed $contents, PdfObjectRepository $repository, array &$warnings): array
    {
        if ($contents === null) {
            return [];
        }

        if ($contents instanceof PdfReference) {
            return [$this->resolveContentStream($repository->resolve($contents), $repository, $warnings)];
        }

        if ($contents instanceof PdfStream) {
            return [$this->resolveContentStream($contents, $repository, $warnings)];
        }

        if (is_array($contents)) {
            $streams = [];

            foreach ($contents as $item) {
                if ($item instanceof PdfReference) {
                    $item = $repository->resolve($item);
                }

                $streams[] = $this->resolveContentStream($item, $repository, $warnings);
            }

            return $streams;
        }

        throw new PdfException('Unsupported page Contents value.');
    }

    /**
     * @param list<string> $warnings
     */
    private function resolveContentStream(mixed $value, PdfObjectRepository $repository, array &$warnings): ParsedContentStream
    {
        if (!$value instanceof PdfStream) {
            throw new PdfException('Expected page content to resolve to a stream.');
        }

        $streamWarnings = [];
        $dictionary = $value->dictionary;
        $contents = $value->contents;

        if (isset($value->dictionary['Filter'])) {
            try {
                $contents = $repository->decodeStream($value);
                unset($dictionary['Filter'], $dictionary['DecodeParms']);
            } catch (PdfException $exception) {
                $streamWarnings[] = sprintf(
                    'Encountered unsupported filtered page content stream; raw bytes were preserved without decoding: %s',
                    $exception->getMessage()
                );
            }
        }

        if (isset($value->dictionary['__warnings']) && is_array($value->dictionary['__warnings'])) {
            foreach ($value->dictionary['__warnings'] as $warning) {
                if (is_string($warning)) {
                    $streamWarnings[] = $warning;
                }
            }
        }

        $operations = [];

        if (!isset($dictionary['Filter'])) {
            try {
                $operations = (new ContentStreamParser($contents))->parse($streamWarnings);
            } catch (PdfException $exception) {
                $streamWarnings[] = sprintf(
                    'Unable to parse content-stream operators: %s',
                    $exception->getMessage()
                );
            }
        }

        $warnings = [...$warnings, ...$streamWarnings];

        return new ParsedContentStream(
            contents: $contents,
            dictionary: $dictionary,
            warnings: $streamWarnings,
            operations: $operations,
        );
    }

    /**
     * @return list<mixed>
     */
    private function resolveArray(mixed $value, PdfObjectRepository $repository): array
    {
        if ($value instanceof PdfReference) {
            $value = $repository->resolve($value);
        }

        if (!is_array($value)) {
            throw new PdfException('Expected array value in page tree.');
        }

        return array_values($value);
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function extractObjectNumber(array $dictionary): int
    {
        return isset($dictionary['__objectNumber']) && is_int($dictionary['__objectNumber'])
            ? $dictionary['__objectNumber']
            : 0;
    }
}
