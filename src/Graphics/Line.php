<?php

declare(strict_types=1);

namespace PdfToolkit\Graphics;

final readonly class Line
{
    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        public float $width = 1.0,
        public ?Color $strokeColor = null,
    ) {
    }
}
