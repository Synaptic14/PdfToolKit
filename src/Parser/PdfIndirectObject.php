<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class PdfIndirectObject
{
    public function __construct(
        public int $objectNumber,
        public int $generationNumber,
        public mixed $value,
        public int $offset,
        public string $serializedValue,
    ) {
    }
}
