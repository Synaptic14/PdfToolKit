<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final readonly class OpenAction
{
    private function __construct(
        public ?int $pageNumber,
        public ?string $destinationName,
        public ?float $left = null,
        public ?float $top = null,
        public ?float $zoom = null,
    ) {
    }

    public static function toPage(
        int $pageNumber,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        return new self($pageNumber, null, $left, $top, $zoom);
    }

    public static function toNamedDestination(string $name): self
    {
        return new self(null, $name);
    }
}
