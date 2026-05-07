<?php

declare(strict_types=1);

namespace PdfToolkit\Image;

final readonly class ImageXObject
{
    /**
     * @param array<string, mixed> $dictionary
     * @param array{dictionary: array<string, mixed>, data: string}|null $iccProfile
     */
    public function __construct(
        public string $key,
        public string $path,
        public int $width,
        public int $height,
        public array $dictionary,
        public string $data,
        public ?ImageXObject $softMask = null,
        public ?array $iccProfile = null,
    ) {
    }
}
