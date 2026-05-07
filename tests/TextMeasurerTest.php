<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Pdf;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\TrueTypeFontParser;
use PdfToolkit\Text\TrueTypeTextShaper;
use PdfToolkit\Text\TextMeasurer;
use PHPUnit\Framework\TestCase;

final class TextMeasurerTest extends TestCase
{
    public function testMeasuresHelveticaTextWidth(): void
    {
        $width = (new TextMeasurer())->width('Hello', 12);

        $this->assertEqualsWithDelta(27.34, $width, 0.01);
    }

    public function testMeasuresCourierAsMonospace(): void
    {
        $measurer = new TextMeasurer();

        $this->assertEqualsWithDelta(
            $measurer->width('WWWWW', 10, new FontReference('Courier')),
            $measurer->width('iiiii', 10, new FontReference('Courier')),
            0.01
        );
    }

    public function testProportionalFontsHaveDifferentCharacterWidths(): void
    {
        $measurer = new TextMeasurer();

        $this->assertGreaterThan(
            $measurer->width('iiiii', 10, new FontReference('Helvetica')),
            $measurer->width('WWWWW', 10, new FontReference('Helvetica'))
        );
    }

    public function testPdfFacadeCanMeasureText(): void
    {
        $this->assertEqualsWithDelta(
            36.0,
            Pdf::measureText('Hello', 12, Pdf::font('Courier')),
            0.01
        );
    }

    public function testMeasuresCustomTrueTypeFonts(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for custom font measurement.');
        }

        $this->assertGreaterThan(
            0,
            Pdf::measureText('Hello', 12, Pdf::trueTypeFont($fontPath, 'CustomTTF'))
        );
    }

    public function testMeasuresCustomTrueTypeCollectionFonts(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Menlo' | head -n 1 2>/dev/null"));

        if (!str_ends_with(strtolower($fontPath), '.ttc') || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType collection font is required for custom TTC measurement.');
        }

        $this->assertGreaterThan(
            0,
            Pdf::measureText('Hello', 12, Pdf::trueTypeFont($fontPath, 'CustomTTC'))
        );
    }

    public function testMeasuresSelectedTrueTypeCollectionFaceFonts(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Menlo' | head -n 1 2>/dev/null"));

        if (!str_ends_with(strtolower($fontPath), '.ttc') || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType collection font is required for TTC face measurement.');
        }

        $this->assertGreaterThan(
            0,
            Pdf::measureText('Hello', 12, Pdf::trueTypeFont($fontPath, 'CustomTTCFace1', faceIndex: 1))
        );
    }

    public function testMeasuresSupplementaryPlaneTrueTypeFonts(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Noto Sans Gothic' | head -n 1 2>/dev/null"));

        if (!str_ends_with(strtolower($fontPath), '.ttf') || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font with supplementary-plane glyphs is required.');
        }

        $this->assertGreaterThan(
            0,
            Pdf::measureText('𐍈', 12, Pdf::trueTypeFont($fontPath, 'SupplementaryTTF'))
        );
    }

    public function testMeasuresUnicodeMappableLigaturesUsingShapedGlyphWidth(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for ligature measurement tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $candidate = $this->ligatureCandidate($parsedFont);

        if ($candidate === null) {
            $this->markTestSkipped('The local TrueType fixture does not expose a Unicode-mappable GSUB ligature.');
        }

        [$sourceText, $ligatureCharacter] = $candidate;
        $measurer = new TextMeasurer();

        $this->assertEqualsWithDelta(
            $measurer->width($ligatureCharacter, 12, Pdf::trueTypeFont($fontPath, 'LigatureTTF')),
            $measurer->width($sourceText, 12, Pdf::trueTypeFont($fontPath, 'LigatureTTF')),
            0.01
        );
        $this->assertSame(
            $ligatureCharacter,
            (new TrueTypeTextShaper())->shape($sourceText, $parsedFont),
        );
    }

    public function testTrueTypeFontHelperDerivesFamilyFromPathWhenOmitted(): void
    {
        $font = FontReference::trueType('/tmp/Example Font.ttf');

        $this->assertSame('Example Font', $font->family);
        $this->assertSame('/tmp/Example Font.ttf', $font->sourcePath);
        $this->assertSame('normal', $font->style);
    }

    public function testTrueTypeFontHelperPreservesFaceIndex(): void
    {
        $font = FontReference::trueType('/tmp/Example.ttc', 'Example', faceIndex: 2);

        $this->assertSame(2, $font->faceIndex);
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
