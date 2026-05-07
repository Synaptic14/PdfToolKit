<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Text\CharacterMap;
use PdfToolkit\Text\ParsedTrueTypeFont;
use PdfToolkit\Text\TrueTypeFontParser;
use PdfToolkit\Text\TrueTypeTextShaper;
use PHPUnit\Framework\TestCase;

final class TrueTypeTextShaperTest extends TestCase
{
    public function testShapesUnicodeMappableLigaturesWhenAvailable(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for ligature shaping tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $candidate = $this->ligatureCandidate($parsedFont);

        if ($candidate === null) {
            $this->markTestSkipped('The local TrueType fixture does not expose a Unicode-mappable GSUB ligature.');
        }

        [$sourceText, $ligatureCharacter] = $candidate;

        $this->assertSame(
            $ligatureCharacter,
            (new TrueTypeTextShaper())->shape($sourceText, $parsedFont),
        );
    }

    public function testAppliesSingleSubstitutionsBeforeLigaturesAndPreservesSourceKeys(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'Synthetic',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x0061 => 10,
                0x0301 => 11,
                0x00E1 => 12,
                0xFB03 => 13,
            ],
            glyphCodePoints: [
                10 => 0x0061,
                11 => 0x0301,
                12 => 0x00E1,
                13 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [
                10 => 12,
            ],
            alternateSubstitutions: [],
            multipleSubstitutions: [],
            ligatureSubstitutions: [
                '12:11' => 13,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape("a\u{0301}", $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey("a\u{0301}", "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens("a\u{0301}", $font));
    }

    public function testAppliesChainedSingleSubstitutionsBeforeLigatures(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticChain',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x0061 => 10,
                0x0062 => 11,
                0x0063 => 12,
                0x00E1 => 13,
                0x00E2 => 14,
                0xFB03 => 15,
            ],
            glyphCodePoints: [
                10 => 0x0061,
                11 => 0x0062,
                12 => 0x0063,
                13 => 0x00E1,
                14 => 0x00E2,
                15 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [
                10 => 13,
                13 => 14,
            ],
            alternateSubstitutions: [],
            multipleSubstitutions: [],
            ligatureSubstitutions: [
                '14:11' => 15,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape('ab', $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey('ab', "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens('ab', $font));
    }

    public function testAppliesLigaturesIterativelyWhenEarlierLigaturesFeedLaterLigatures(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticLigatureChain',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x0061 => 10,
                0x0062 => 11,
                0x0063 => 12,
                0xFB00 => 20,
                0xFB03 => 21,
            ],
            glyphCodePoints: [
                10 => 0x0061,
                11 => 0x0062,
                12 => 0x0063,
                20 => 0xFB00,
                21 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [],
            alternateSubstitutions: [],
            multipleSubstitutions: [],
            ligatureSubstitutions: [
                '10:11' => 20,
                '20:12' => 21,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape('abc', $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey('abc', "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens('abc', $font));
    }

    public function testAppliesUnicodeMappableAlternateSubstitutionsBeforeLigatures(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticAlternate',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x0061 => 10,
                0x0062 => 11,
                0x00E1 => 12,
                0xE001 => 13,
                0xFB03 => 14,
            ],
            glyphCodePoints: [
                10 => 0x0061,
                11 => 0x0062,
                12 => 0x00E1,
                14 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [],
            alternateSubstitutions: [
                10 => [13, 12],
            ],
            multipleSubstitutions: [],
            ligatureSubstitutions: [
                '12:11' => 14,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape('ab', $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey('ab', "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens('ab', $font));
    }

    public function testFallsBackToUnicodeAlternateWhenSingleSubstitutionIsUnmappable(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticAlternateFallback',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x0061 => 10,
                0x0062 => 11,
                0x00E1 => 12,
                0xE001 => 13,
                0xFB03 => 14,
            ],
            glyphCodePoints: [
                10 => 0x0061,
                11 => 0x0062,
                12 => 0x00E1,
                14 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [
                10 => 13,
            ],
            alternateSubstitutions: [
                10 => [12],
            ],
            multipleSubstitutions: [],
            ligatureSubstitutions: [
                '12:11' => 14,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape('ab', $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey('ab', "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens('ab', $font));
    }

    public function testAppliesUnicodeMappableMultipleSubstitutionsBeforeLigatures(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticMultiple',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x00E4 => 10,
                0x0061 => 11,
                0x0062 => 12,
                0xFB03 => 13,
            ],
            glyphCodePoints: [
                10 => 0x00E4,
                11 => 0x0061,
                12 => 0x0062,
                13 => 0xFB03,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [],
            alternateSubstitutions: [],
            multipleSubstitutions: [
                10 => [11, 12],
            ],
            ligatureSubstitutions: [
                '11:12' => 13,
            ],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame("\u{FB03}", $shaper->shape("\u{00E4}", $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey("\u{00E4}", "\u{FB03}"),
                'display' => "\u{FB03}",
            ],
        ], $shaper->shapeTokens("\u{00E4}", $font));
    }

    public function testPreservesOriginalSourceAcrossExpandedMultipleSubstitutionTokens(): void
    {
        $font = new ParsedTrueTypeFont(
            postScriptName: 'SyntheticMultipleExpanded',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [
                0x00E4 => 10,
                0x0061 => 11,
                0x0062 => 12,
            ],
            glyphCodePoints: [
                10 => 0x00E4,
                11 => 0x0061,
                12 => 0x0062,
            ],
            advanceWidths: [500, 500, 500, 500],
            kerningPairs: [],
            singleSubstitutions: [],
            alternateSubstitutions: [],
            multipleSubstitutions: [
                10 => [11, 12],
            ],
            ligatureSubstitutions: [],
        );

        $shaper = new TrueTypeTextShaper();

        $this->assertSame('ab', $shaper->shape("\u{00E4}", $font));
        $this->assertSame([
            [
                'key' => CharacterMap::sourceKey("\u{00E4}", 'a'),
                'display' => 'a',
            ],
            [
                'key' => CharacterMap::sourceKey('', 'b'),
                'display' => 'b',
            ],
        ], $shaper->shapeTokens("\u{00E4}", $font));
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function ligatureCandidate(object $parsedFont): ?array
    {
        foreach ($parsedFont->ligatureSubstitutions as $sequenceKey => $ligatureGlyphId) {
            $ligatureCodePoint = $parsedFont->codePointForGlyphId($ligatureGlyphId);

            if ($ligatureCodePoint === null) {
                continue;
            }

            $sourceText = '';

            foreach (explode(':', $sequenceKey) as $glyphIdString) {
                $codePoint = $parsedFont->codePointForGlyphId((int) $glyphIdString);

                if ($codePoint === null) {
                    continue 2;
                }

                $sourceText .= mb_chr($codePoint);
            }

            if (mb_strlen($sourceText) < 2) {
                continue;
            }

            return [$sourceText, mb_chr($ligatureCodePoint)];
        }

        return null;
    }
}
