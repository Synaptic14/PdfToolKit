<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Text\ParsedTrueTypeFont;
use PdfToolkit\Text\TrueTypeFontParser;
use PHPUnit\Framework\TestCase;

final class TrueTypeFontParserTest extends TestCase
{
    public function testParsesKerningPairsWhenAvailable(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for kerning parser tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $leftGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('A'));
        $rightGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('V'));

        if ($leftGlyphId === null || $rightGlyphId === null || $parsedFont->kerningForGlyphPair($leftGlyphId, $rightGlyphId) === 0) {
            $this->markTestSkipped('The local TrueType fixture does not expose an AV kerning pair.');
        }

        $this->assertNotSame(0, $parsedFont->kerningForGlyphPair($leftGlyphId, $rightGlyphId));
    }

    public function testParsesFixedPitchTraitWhenAvailable(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Menlo' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local fixed-pitch TrueType or TTC font is required for parser trait tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);

        $this->assertTrue($parsedFont->isFixedPitch);
        $this->assertSame(33, $parsedFont->descriptorFlags());
    }

    public function testParsedFontEmbeddingRightsHelpers(): void
    {
        $restricted = new ParsedTrueTypeFont(
            postScriptName: 'RestrictedFont',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            fontBBox: [0, -200, 1000, 900],
            capHeight: 700,
            xHeight: 500,
            weightClass: 400,
            fsType: 0x0002 | 0x0100,
            italicAngle: 0.0,
            isFixedPitch: false,
            isItalic: false,
            isBold: false,
            glyphMap: [],
            glyphCodePoints: [],
            advanceWidths: [500],
            singleSubstitutions: [],
            alternateSubstitutions: [],
            multipleSubstitutions: [],
            ligatureSubstitutions: [],
        );

        $this->assertFalse($restricted->allowsEmbedding());
        $this->assertSame('restricted-license embedding', $restricted->embeddingRightsDescription());
        $this->assertTrue($restricted->disallowsSubsetting());
    }

    public function testParsesGposPairPositioningFormat1Kerning(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseGposKerningPairs');
        $method->setAccessible(true);

        $gpos = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'kern'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x02\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x0C\x00\x04\x00\x00\x00\x01\x00\x12"
            . "\x00\x01\x00\x01\x00\x0A"
            . "\x00\x01\x00\x0B\xFF\xD8";

        $this->assertSame(['10:11' => -40], $method->invoke($parser, $gpos));
    }

    public function testParsesGposPairPositioningFormat2Kerning(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseGposKerningPairs');
        $method->setAccessible(true);

        $gpos = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'kern'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x02\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x18\x00\x04\x00\x00\x00\x1E\x00\x26\x00\x02\x00\x02"
            . "\x00\x00\x00\x00\x00\x00\xFF\xE2"
            . "\x00\x01\x00\x01\x00\x14"
            . "\x00\x01\x00\x14\x00\x01\x00\x01"
            . "\x00\x01\x00\x15\x00\x01\x00\x01";

        $this->assertSame(['20:21' => -30], $method->invoke($parser, $gpos));
    }

    public function testParsesGsubSingleSubstitutionFormat2(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'ccmp'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x29"
            . "\x00\x01\x00\x01\x00\x28";

        $this->assertSame([40 => 41], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromRvrnFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'rvrn'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x31"
            . "\x00\x01\x00\x01\x00\x30";

        $this->assertSame([48 => 49], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromSmallCapsFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'smcp'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x41"
            . "\x00\x01\x00\x01\x00\x40";

        $this->assertSame([64 => 65], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromFullWidthFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'fwid'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x61"
            . "\x00\x01\x00\x01\x00\x60";

        $this->assertSame([96 => 97], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromOldstyleFiguresFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'onum'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x71"
            . "\x00\x01\x00\x01\x00\x70";

        $this->assertSame([112 => 113], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromNumeratorFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'numr'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\x81"
            . "\x00\x01\x00\x01\x00\x80";

        $this->assertSame([128 => 129], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubSingleSubstitutionFormat2FromVerticalFormsFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseSingleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'vert'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x01\x00\x00\x00\x01\x00\x08"
            . "\x00\x02\x00\x08\x00\x01\x00\xA1"
            . "\x00\x01\x00\x01\x00\xA0";

        $this->assertSame([160 => 161], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubAlternateSubstitutionFormat1(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseAlternateSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'salt'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x03\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x28"
            . "\x00\x02\x00\x29\x00\x2A";

        $this->assertSame([40 => [41, 42]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubAlternateSubstitutionFormat1FromStylisticSetFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseAlternateSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'ss01'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x03\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x38"
            . "\x00\x02\x00\x39\x00\x3A";

        $this->assertSame([56 => [57, 58]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubAlternateSubstitutionFormat1FromHistoricalFormsFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseAlternateSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'hist'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x03\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x48"
            . "\x00\x02\x00\x49\x00\x4A";

        $this->assertSame([72 => [73, 74]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubAlternateSubstitutionFormat1FromOrnamentsFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseAlternateSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'ornm'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x03\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x58"
            . "\x00\x02\x00\x59\x00\x5A";

        $this->assertSame([88 => [89, 90]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubAlternateSubstitutionFormat1FromJis78Feature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseAlternateSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'jp78'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x03\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x68"
            . "\x00\x02\x00\x69\x00\x6A";

        $this->assertSame([104 => [105, 106]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubMultipleSubstitutionFormat1(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseMultipleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'ccmp'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x02\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x28"
            . "\x00\x02\x00\x29\x00\x2A";

        $this->assertSame([40 => [41, 42]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubMultipleSubstitutionFormat1FromContextualAlternatesFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseMultipleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'calt'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x02\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x32"
            . "\x00\x02\x00\x33\x00\x34";

        $this->assertSame([50 => [51, 52]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubMultipleSubstitutionFormat1FromFractionsFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseMultipleSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'frac'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x02\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x90"
            . "\x00\x02\x00\x91\x00\x92";

        $this->assertSame([144 => [145, 146]], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubLigatureSubstitutionsFromDiscretionaryLigatureFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseLigatureSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'dlig'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x04\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x1E"
            . "\x00\x01\x00\x04"
            . "\x00\x1E\x00\x03\x00\x1F\x00\x20";

        $this->assertSame(['30:31:32' => 30], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubLigatureSubstitutionsFromHistoricalLigatureFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseLigatureSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'hlig'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x04\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x28"
            . "\x00\x01\x00\x04"
            . "\x00\x28\x00\x03\x00\x29\x00\x2A";

        $this->assertSame(['40:41:42' => 40], $method->invoke($parser, $gsub));
    }

    public function testParsesGsubLigatureSubstitutionsFromContextualAlternatesFeature(): void
    {
        $parser = new TrueTypeFontParser();
        $method = new \ReflectionMethod($parser, 'parseLigatureSubstitutions');
        $method->setAccessible(true);

        $gsub = ''
            . "\x00\x01\x00\x00"
            . "\x00\x00"
            . "\x00\x0A"
            . "\x00\x18"
            . "\x00\x01"
            . 'calt'
            . "\x00\x08"
            . "\x00\x00\x00\x01\x00\x00"
            . "\x00\x01\x00\x04"
            . "\x00\x04\x00\x00\x00\x01\x00\x08"
            . "\x00\x01\x00\x08\x00\x01\x00\x0E"
            . "\x00\x01\x00\x01\x00\x50"
            . "\x00\x01\x00\x04"
            . "\x00\x50\x00\x03\x00\x51\x00\x52";

        $this->assertSame(['80:81:82' => 80], $method->invoke($parser, $gsub));
    }
}
