<?php

declare(strict_types=1);

namespace PdfToolkit\Navigation;

final readonly class PageLabelRange
{
    public const DECIMAL = 'D';
    public const ROMAN_UPPER = 'R';
    public const ROMAN_LOWER = 'r';
    public const LETTERS_UPPER = 'A';
    public const LETTERS_LOWER = 'a';

    public function __construct(
        public int $startPage,
        public ?string $style = self::DECIMAL,
        public ?string $prefix = null,
        public int $startNumber = 1,
    ) {
    }
}
