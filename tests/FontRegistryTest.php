<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\FontRegistry;
use PdfToolkit\Text\ParsedTrueTypeFont;
use PdfToolkit\Text\TrueTypeFontParser;
use PHPUnit\Framework\TestCase;

final class FontRegistryTest extends TestCase
{
    public function testRejectsRestrictedLicenseTrueTypeEmbedding(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'pdftoolbox-font');

        if ($tempPath === false) {
            $this->fail('Unable to create a temporary font fixture.');
        }

        $fontPath = $tempPath . '.ttf';
        rename($tempPath, $fontPath);
        file_put_contents($fontPath, 'dummy');

        $parser = new class extends TrueTypeFontParser {
            public function parse(string $path, int $faceIndex = 0): ParsedTrueTypeFont
            {
                return new ParsedTrueTypeFont(
                    postScriptName: 'RestrictedFont',
                    unitsPerEm: 1000,
                    ascent: 800,
                    descent: -200,
                    lineGap: 0,
                    fontBBox: [0, -200, 1000, 900],
                    capHeight: 700,
                    xHeight: 500,
                    weightClass: 400,
                    fsType: 0x0002,
                    italicAngle: 0.0,
                    isFixedPitch: false,
                    isItalic: false,
                    isBold: false,
                    glyphMap: [],
                    glyphCodePoints: [],
                    advanceWidths: [500],
                );
            }
        };

        try {
            $registry = new FontRegistry($parser);

            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('restricted-license embedding');

            $registry->resolve(FontReference::trueType($fontPath, 'RestrictedFont'));
        } finally {
            @unlink($fontPath);
        }
    }
}
