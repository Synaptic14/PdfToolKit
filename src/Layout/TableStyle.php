<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Graphics\Color;

final readonly class TableStyle
{
    public function __construct(
        public PageMargins $cellPadding,
        public ?Color $borderColor = null,
        public ?Color $headerFillColor = null,
        public ?Color $rowFillColor = null,
        public ?Color $alternateRowFillColor = null,
        public float $lineWidth = 1.0,
    ) {
        if ($this->lineWidth < 0.0) {
            throw new \InvalidArgumentException('Table line width must be zero or greater.');
        }
    }

    public static function padded(
        float $padding,
        ?Color $borderColor = null,
        ?Color $headerFillColor = null,
        ?Color $rowFillColor = null,
        ?Color $alternateRowFillColor = null,
        float $lineWidth = 1.0,
    ): self {
        return new self(
            PageMargins::all($padding),
            $borderColor,
            $headerFillColor,
            $rowFillColor,
            $alternateRowFillColor,
            $lineWidth,
        );
    }
}
