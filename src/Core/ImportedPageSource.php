<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class ImportedPageSource
{
    /**
     * @param array<string, mixed> $pageDictionary
     * @param array<string, mixed>|null $resourceDictionary
     * @param list<ImportedContentStream> $contentStreams
     * @param array<int, string> $dependentObjects
     * @param list<string> $warnings
     */
    public function __construct(
        public int $objectNumber,
        public array $pageDictionary,
        public ?array $resourceDictionary,
        public array $contentStreams,
        public array $dependentObjects = [],
        public array $warnings = [],
    ) {
    }
}
