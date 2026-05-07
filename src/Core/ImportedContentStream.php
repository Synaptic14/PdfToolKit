<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class ImportedContentStream
{
    /**
     * @param array<string, mixed> $dictionary
     * @param list<string> $warnings
     * @param list<ImportedContentOperation> $operations
     */
    public function __construct(
        public string $contents,
        public array $dictionary = [],
        public array $warnings = [],
        public array $operations = [],
    ) {
    }
}
