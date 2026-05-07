<?php

declare(strict_types=1);

namespace PdfToolkit\Outline;

final readonly class OutlineItem
{
    public function __construct(
        public string $title,
        public int $pageNumber,
        public int $level = 0,
        public ?float $left = null,
        public ?float $top = null,
        public ?float $zoom = null,
    ) {
    }
}
