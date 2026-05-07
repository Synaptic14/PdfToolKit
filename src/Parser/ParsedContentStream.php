<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedContentStream
{
    /**
     * @param array<string, mixed> $dictionary
     * @param list<string> $warnings
     * @param list<ParsedContentOperation> $operations
     */
    public function __construct(
        public string $contents,
        public array $dictionary = [],
        public array $warnings = [],
        public array $operations = [],
    ) {
    }
}
