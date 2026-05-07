<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use PdfToolkit\Core\Page;

final readonly class TextFrame
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($this->width <= 0.0) {
            throw new \InvalidArgumentException('Text frame width must be greater than zero.');
        }

        if ($this->height <= 0.0) {
            throw new \InvalidArgumentException('Text frame height must be greater than zero.');
        }

        if ($this->x < 0.0 || $this->y < 0.0) {
            throw new \InvalidArgumentException('Text frame coordinates must be zero or greater.');
        }
    }

    public function bottom(): float
    {
        return $this->y + $this->height;
    }

    public static function fromPage(Page $page, PageMargins $margins): self
    {
        $width = $page->width() - $margins->left - $margins->right;
        $height = $page->height() - $margins->top - $margins->bottom;

        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException('Page margins leave no room for a content frame.');
        }

        return new self(
            x: $margins->left,
            y: $margins->top,
            width: $width,
            height: $height,
        );
    }

    public function inset(PageMargins $margins): self
    {
        $width = $this->width - $margins->left - $margins->right;
        $height = $this->height - $margins->top - $margins->bottom;

        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException('Insets leave no room inside the text frame.');
        }

        return new self(
            x: $this->x + $margins->left,
            y: $this->y + $margins->top,
            width: $width,
            height: $height,
        );
    }
}
