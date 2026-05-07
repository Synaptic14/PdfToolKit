<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class EncodedText
{
    public function __construct(
        public string $bytes,
        public bool $hex = true,
    ) {
    }
}
