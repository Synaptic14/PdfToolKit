<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Graphics\Color;
use PdfToolkit\Text\FontReference;

final readonly class TextBlock
{
    public function __construct(
        public string $text,
        public float $fontSize = 12.0,
        public ?FontReference $font = null,
        public ?Color $color = null,
        public float $lineHeight = 1.2,
        public float $paragraphSpacing = 0.35,
        public float $spacingAfter = 0.0,
    ) {
        if ($this->fontSize <= 0.0) {
            throw new \InvalidArgumentException('Text block font size must be greater than zero.');
        }

        if ($this->lineHeight <= 0.0) {
            throw new \InvalidArgumentException('Text block line height must be greater than zero.');
        }

        if ($this->paragraphSpacing < 0.0) {
            throw new \InvalidArgumentException('Text block paragraph spacing must be zero or greater.');
        }

        if ($this->spacingAfter < 0.0) {
            throw new \InvalidArgumentException('Text block spacing after must be zero or greater.');
        }
    }
}
