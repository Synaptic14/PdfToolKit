<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedNameTree
{
    /**
     * @param array<int, string> $dependentObjects
     */
    public function __construct(
        public int $objectNumber,
        public array $dependentObjects = [],
    ) {
    }
}
