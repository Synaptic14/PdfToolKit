<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class ResolvedFont
{
    public function __construct(
        public string $family,
        public string $style,
        public string $baseFont,
        public bool $embed = false,
        public ?string $sourcePath = null,
        public string $subtype = 'Type1',
        public int $faceIndex = 0,
    ) {
    }

    public function key(): string
    {
        return $this->sourcePath !== null
            ? strtolower($this->subtype . ':' . $this->sourcePath . '#' . $this->faceIndex)
            : strtolower($this->baseFont);
    }
}
