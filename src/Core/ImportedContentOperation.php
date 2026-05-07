<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class ImportedContentOperation
{
    /**
     * @param list<mixed> $operands
     */
    public function __construct(
        public string $operator,
        public array $operands = [],
    ) {
    }
}
