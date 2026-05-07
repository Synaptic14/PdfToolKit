<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final readonly class NamedDestination
{
    public function __construct(
        public string $name,
        public int $pageNumber,
        public ?float $left = null,
        public ?float $top = null,
        public ?float $zoom = null,
    ) {
    }
}
