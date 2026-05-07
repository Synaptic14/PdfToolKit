<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class PdfReference
{
    public function __construct(
        public int $objectNumber,
        public int $generationNumber = 0,
    ) {
    }
}
