<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class ParsedTrueTypeFont
{
    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $fontBBox
     * @param array<int, int> $glyphMap
     * @param array<int, int> $glyphCodePoints
     * @param array<int, int> $advanceWidths
     * @param array<string, int> $kerningPairs
     * @param array<int, int> $singleSubstitutions
     * @param array<int, list<int>> $alternateSubstitutions
     * @param array<int, list<int>> $multipleSubstitutions
     * @param array<string, int> $ligatureSubstitutions
     */
    public function __construct(
        public ?string $postScriptName,
        public int $unitsPerEm,
        public int $ascent,
        public int $descent,
        public int $lineGap,
        public array $fontBBox,
        public int $capHeight,
        public int $xHeight,
        public int $weightClass,
        public int $fsType,
        public float $italicAngle,
        public bool $isFixedPitch,
        public bool $isItalic,
        public bool $isBold,
        public array $glyphMap,
        public array $glyphCodePoints,
        public array $advanceWidths,
        public array $kerningPairs = [],
        public array $singleSubstitutions = [],
        public array $alternateSubstitutions = [],
        public array $multipleSubstitutions = [],
        public array $ligatureSubstitutions = [],
    ) {
    }

    public function glyphIdForCodePoint(int $codePoint): ?int
    {
        return $this->glyphMap[$codePoint] ?? null;
    }

    public function widthForGlyphId(int $glyphId): int
    {
        return $this->advanceWidths[$glyphId] ?? ($this->advanceWidths[0] ?? $this->unitsPerEm);
    }

    public function kerningForGlyphPair(int $leftGlyphId, int $rightGlyphId): int
    {
        return $this->kerningPairs[$leftGlyphId . ':' . $rightGlyphId] ?? 0;
    }

    public function codePointForGlyphId(int $glyphId): ?int
    {
        return $this->glyphCodePoints[$glyphId] ?? null;
    }

    public function singleSubstitutionGlyphIdForGlyphId(int $glyphId): ?int
    {
        return $this->singleSubstitutions[$glyphId] ?? null;
    }

    public function alternateSubstitutionGlyphIdsForGlyphId(int $glyphId): array
    {
        return $this->alternateSubstitutions[$glyphId] ?? [];
    }

    public function multipleSubstitutionGlyphIdsForGlyphId(int $glyphId): array
    {
        return $this->multipleSubstitutions[$glyphId] ?? [];
    }

    /**
     * @param list<int> $glyphIds
     */
    public function ligatureGlyphIdForSequence(array $glyphIds): ?int
    {
        return $this->ligatureSubstitutions[implode(':', $glyphIds)] ?? null;
    }

    public function descriptorFlags(): int
    {
        $flags = 32;

        if ($this->isFixedPitch) {
            $flags |= 1;
        }

        if ($this->isItalic) {
            $flags |= 64;
        }

        if ($this->isBold) {
            $flags |= 262144;
        }

        return $flags;
    }

    public function stemV(): int
    {
        return max(50, min(220, (int) round(($this->weightClass / 1000) * 180)));
    }

    public function averageWidth(): int
    {
        if ($this->advanceWidths === []) {
            return $this->unitsPerEm;
        }

        return (int) round(array_sum($this->advanceWidths) / count($this->advanceWidths));
    }

    public function maxWidth(): int
    {
        return $this->advanceWidths === []
            ? $this->unitsPerEm
            : max($this->advanceWidths);
    }

    public function missingWidth(): int
    {
        return $this->advanceWidths[0] ?? $this->unitsPerEm;
    }

    public function allowsEmbedding(): bool
    {
        return ($this->fsType & 0x0002) !== 0x0002;
    }

    public function embeddingRightsDescription(): string
    {
        if (($this->fsType & 0x0002) === 0x0002) {
            return 'restricted-license embedding';
        }

        if (($this->fsType & 0x0008) === 0x0008) {
            return 'editable embedding';
        }

        if (($this->fsType & 0x0004) === 0x0004) {
            return 'preview-and-print embedding';
        }

        return 'installable embedding';
    }

    public function disallowsSubsetting(): bool
    {
        return ($this->fsType & 0x0100) === 0x0100;
    }
}
