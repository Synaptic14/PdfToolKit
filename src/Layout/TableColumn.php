<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Graphics\Color;
use PdfToolkit\Text\FontReference;

final readonly class TableColumn
{
    public function __construct(
        public float $width,
        public ?string $align = null,
        public ?string $valign = null,
        public ?PageMargins $padding = null,
        public ?float $fontSize = null,
        public ?FontReference $font = null,
        public ?Color $color = null,
        public ?float $lineHeight = null,
        public ?float $paragraphSpacing = null,
        public ?Color $borderColor = null,
        public ?Color $fillColor = null,
        public ?float $lineWidth = null,
    ) {
        if ($this->width <= 0.0) {
            throw new \InvalidArgumentException('Table column width must be greater than zero.');
        }

        if ($this->fontSize !== null && $this->fontSize <= 0.0) {
            throw new \InvalidArgumentException('Table column font size must be greater than zero.');
        }

        if ($this->align !== null && !in_array($this->align, [
            TableCell::ALIGN_LEFT,
            TableCell::ALIGN_CENTER,
            TableCell::ALIGN_RIGHT,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported table column alignment: %s', $this->align));
        }

        if ($this->valign !== null && !in_array($this->valign, [
            TableCell::VALIGN_TOP,
            TableCell::VALIGN_MIDDLE,
            TableCell::VALIGN_BOTTOM,
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported table column vertical alignment: %s', $this->valign));
        }

        if ($this->lineHeight !== null && $this->lineHeight <= 0.0) {
            throw new \InvalidArgumentException('Table column line height must be greater than zero.');
        }

        if ($this->paragraphSpacing !== null && $this->paragraphSpacing < 0.0) {
            throw new \InvalidArgumentException('Table column paragraph spacing must be zero or greater.');
        }

        if ($this->lineWidth !== null && $this->lineWidth < 0.0) {
            throw new \InvalidArgumentException('Table column line width must be zero or greater.');
        }
    }
}
