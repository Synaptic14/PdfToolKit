<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class ImportedOutlineSource
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
