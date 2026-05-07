<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class TrueTypeFontSubsetResult
{
    /**
     * @param array<int, int> $glyphIdMap
     */
    public function __construct(
        public string $fontProgram,
        public bool $subsetted,
        public array $glyphIdMap = [],
    ) {
    }

    public function mappedGlyphId(int $glyphId): ?int
    {
        return $this->glyphIdMap[$glyphId] ?? null;
    }
}
