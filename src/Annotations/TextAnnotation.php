<?php

declare(strict_types=1);

namespace PdfToolkit\Annotations;

final readonly class TextAnnotation
{
    public function __construct(
        public string $contents,
        public float $x,
        public float $y,
        public float $width = 24.0,
        public float $height = 24.0,
        public bool $open = false,
        public string $icon = 'Note',
    ) {
    }
}
