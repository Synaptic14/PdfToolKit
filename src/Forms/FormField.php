<?php

declare(strict_types=1);

namespace PdfToolkit\Forms;

final readonly class FormField
{
    /**
     * @param array<string, scalar|null> $options
     */
    public function __construct(
        public string $name,
        public string $type,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public array $options = [],
    ) {
    }
}
