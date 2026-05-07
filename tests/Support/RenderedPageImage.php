<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Support;

final readonly class RenderedPageImage
{
    public function __construct(
        public int $width,
        public int $height,
        private string $rgb,
    ) {
    }

    public function countNonWhitePixelsInRect(
        float $x,
        float $y,
        float $width,
        float $height,
        int $threshold = 245,
    ): int {
        $startX = max(0, (int) floor($x));
        $startY = max(0, (int) floor($y));
        $endX = min($this->width, (int) ceil($x + $width));
        $endY = min($this->height, (int) ceil($y + $height));

        if ($endX <= $startX || $endY <= $startY) {
            return 0;
        }

        $count = 0;

        for ($row = $startY; $row < $endY; $row++) {
            for ($column = $startX; $column < $endX; $column++) {
                $offset = (($row * $this->width) + $column) * 3;
                $r = ord($this->rgb[$offset]);
                $g = ord($this->rgb[$offset + 1]);
                $b = ord($this->rgb[$offset + 2]);

                if ($r < $threshold || $g < $threshold || $b < $threshold) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
