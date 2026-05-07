<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Graphics\Color;
use PdfToolkit\Text\FontReference;

final readonly class TableCell
{
    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';
    public const VALIGN_TOP = 'top';
    public const VALIGN_MIDDLE = 'middle';
    public const VALIGN_BOTTOM = 'bottom';

    public function __construct(
        public string $text,
        public ?float $fontSize = null,
        public ?FontReference $font = null,
        public ?Color $color = null,
        public ?float $lineHeight = null,
        public ?float $paragraphSpacing = null,
        public ?string $align = null,
        public ?string $valign = null,
        public ?Color $borderColor = null,
        public ?Color $fillColor = null,
        public ?float $lineWidth = null,
        public int $colspan = 1,
        public int $rowspan = 1,
    ) {
        if ($this->fontSize !== null && $this->fontSize <= 0.0) {
            throw new \InvalidArgumentException('Table cell font size must be greater than zero.');
        }

        if ($this->lineHeight !== null && $this->lineHeight <= 0.0) {
            throw new \InvalidArgumentException('Table cell line height must be greater than zero.');
        }

        if ($this->paragraphSpacing !== null && $this->paragraphSpacing < 0.0) {
            throw new \InvalidArgumentException('Table cell paragraph spacing must be zero or greater.');
        }

        if ($this->align !== null && !in_array($this->align, [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported table cell alignment: %s', $this->align));
        }

        if ($this->valign !== null && !in_array($this->valign, [self::VALIGN_TOP, self::VALIGN_MIDDLE, self::VALIGN_BOTTOM], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported table cell vertical alignment: %s', $this->valign));
        }

        if ($this->lineWidth !== null && $this->lineWidth < 0.0) {
            throw new \InvalidArgumentException('Table cell line width must be zero or greater.');
        }

        if ($this->colspan < 1) {
            throw new \InvalidArgumentException('Table cell colspan must be at least 1.');
        }

        if ($this->rowspan < 1) {
            throw new \InvalidArgumentException('Table cell rowspan must be at least 1.');
        }
    }
}
