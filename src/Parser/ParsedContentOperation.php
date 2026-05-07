<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedContentOperation
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
