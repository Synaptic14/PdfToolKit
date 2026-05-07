<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

final readonly class PageMargins
{
    public function __construct(
        public float $top,
        public float $right,
        public float $bottom,
        public float $left,
    ) {
        foreach ([$this->top, $this->right, $this->bottom, $this->left] as $margin) {
            if ($margin < 0.0) {
                throw new \InvalidArgumentException('Page margins must be zero or greater.');
            }
        }
    }

    public static function all(float $margin): self
    {
        return new self($margin, $margin, $margin, $margin);
    }

    public static function symmetric(float $vertical, float $horizontal): self
    {
        return new self($vertical, $horizontal, $vertical, $horizontal);
    }
}
