<?php

declare(strict_types=1);

namespace PdfToolkit\Image;

final readonly class ImagePlacement
{
    public function __construct(
        public string $path,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?string $data = null,
        public ?string $format = null,
    ) {
    }

    public static function svgData(
        string $svg,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $key = null,
    ): self {
        return new self(
            $key ?? ('raw-svg:' . sha1($svg)),
            $x,
            $y,
            $width,
            $height,
            data: $svg,
            format: 'svg',
        );
    }

    public function hasInlineData(): bool
    {
        return $this->data !== null;
    }
}
