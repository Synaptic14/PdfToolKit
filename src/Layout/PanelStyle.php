<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Graphics\Color;

final readonly class PanelStyle
{
    public function __construct(
        public PageMargins $padding,
        public ?Color $strokeColor = null,
        public ?Color $fillColor = null,
        public float $lineWidth = 1.0,
    ) {
        if ($this->lineWidth < 0.0) {
            throw new \InvalidArgumentException('Panel line width must be zero or greater.');
        }
    }

    public static function padded(
        float $padding,
        ?Color $strokeColor = null,
        ?Color $fillColor = null,
        float $lineWidth = 1.0,
    ): self {
        return new self(PageMargins::all($padding), $strokeColor, $fillColor, $lineWidth);
    }
}
