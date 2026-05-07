<?php

declare(strict_types=1);

namespace PdfToolkit\Annotations;

final readonly class LinkAnnotation
{
    public function __construct(
        public ?string $uri,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public bool $border = false,
        public ?int $pageNumber = null,
        public ?float $left = null,
        public ?float $top = null,
        public ?float $zoom = null,
        public ?string $destinationName = null,
    ) {
    }

    public static function toPage(
        int $pageNumber,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $border = false,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        return new self(null, $x, $y, $width, $height, $border, $pageNumber, $left, $top, $zoom);
    }

    public static function toNamedDestination(
        string $name,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $border = false,
    ): self {
        return new self(null, $x, $y, $width, $height, $border, destinationName: $name);
    }
}
