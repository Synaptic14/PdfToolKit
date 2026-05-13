<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class PageRenderContext
{
    public function __construct(
        public int $pageNumber,
        public int $pageCount,
        public float $pageWidth,
        public float $pageHeight,
    ) {
    }
}
