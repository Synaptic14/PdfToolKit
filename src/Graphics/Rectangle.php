<?php

declare(strict_types=1);

namespace PdfToolkit\Graphics;

final readonly class Rectangle
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?Color $strokeColor = null,
        public ?Color $fillColor = null,
        public float $lineWidth = 1.0,
    ) {
    }
}
