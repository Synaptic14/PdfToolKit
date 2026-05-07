<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class PdfStream
{
    /**
     * @param array<string, mixed> $dictionary
     */
    public function __construct(
        public array $dictionary,
        public string $contents,
    ) {
    }
}
