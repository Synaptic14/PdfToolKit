<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final class TextMeasurer
{
    /** @var array<string, ParsedTrueTypeFont> */
    private array $parsedTrueTypeFonts = [];

    public function __construct(
        private readonly FontRegistry $fontRegistry = new FontRegistry(),
        private readonly StandardFontMetrics $metrics = new StandardFontMetrics(),
        private readonly TrueTypeFontParser $trueTypeFontParser = new TrueTypeFontParser(),
        private readonly TrueTypeTextShaper $trueTypeTextShaper = new TrueTypeTextShaper(),
    ) {
    }

    public function width(string $text, float $fontSize = 12.0, ?FontReference $font = null): float
    {
        $resolved = $this->fontRegistry->resolve($font);

        if ($resolved->sourcePath !== null) {
            return $this->measureTrueTypeWidth($text, $fontSize, $resolved->sourcePath, $resolved->faceIndex);
        }

        $metrics = $this->metrics->forFont($resolved);
        $units = 0;

        foreach ($this->characters($text) as $character) {
            $units += $metrics->widthForCharacter($character);
        }

        return ($units / 1000.0) * $fontSize;
    }

    private function measureTrueTypeWidth(string $text, float $fontSize, string $fontPath, int $faceIndex = 0): float
    {
        if ($text === '') {
            return 0.0;
        }

        $parsedFont = $this->parsedTrueTypeFont($fontPath, $faceIndex);
        $characters = $this->characters($this->trueTypeTextShaper->shape($text, $parsedFont));
        $units = 0;

        foreach ($characters as $index => $character) {
            $glyphId = $parsedFont->glyphIdForCodePoint(mb_ord($character)) ?? 0;
            $units += $parsedFont->widthForGlyphId($glyphId);

            if ($index === array_key_last($characters)) {
                continue;
            }

            $nextGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord($characters[$index + 1])) ?? 0;
            $units += $parsedFont->kerningForGlyphPair($glyphId, $nextGlyphId);
        }

        return ($units / max(1, $parsedFont->unitsPerEm)) * $fontSize;
    }

    /**
     * @return list<string>
     */
    private function characters(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return mb_str_split($text);
    }

    private function parsedTrueTypeFont(string $fontPath, int $faceIndex): ParsedTrueTypeFont
    {
        $key = strtolower($fontPath . '#' . $faceIndex);

        return $this->parsedTrueTypeFonts[$key]
            ??= $this->trueTypeFontParser->parse($fontPath, $faceIndex);
    }
}
