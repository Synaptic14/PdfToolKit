<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class FontMetrics
{
    /**
     * @param array<string, int> $widths
     */
    public function __construct(
        public string $baseFont,
        public int $defaultWidth,
        public array $widths = [],
    ) {
    }

    public function widthForCharacter(string $character): int
    {
        return $this->widths[$character] ?? $this->defaultWidth;
    }
}
