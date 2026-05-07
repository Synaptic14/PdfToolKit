<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final readonly class MarkInfo
{
    public function __construct(
        public bool $marked = true,
        public ?bool $userProperties = null,
        public ?bool $suspects = null,
    ) {
    }
}
