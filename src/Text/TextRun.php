<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Graphics\Color;

final readonly class TextRun
{
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $fontSize = 12.0,
        public ?FontReference $font = null,
        public ?Color $color = null,
    ) {
    }
}
