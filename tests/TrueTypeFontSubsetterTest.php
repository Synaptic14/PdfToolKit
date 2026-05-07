<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Text\TrueTypeFontParser;
use PdfToolkit\Text\TrueTypeFontSubsetter;
use PHPUnit\Framework\TestCase;

final class TrueTypeFontSubsetterTest extends TestCase
{
    public function testCompactsUnusedGlyphBodiesWithinRetainedGlyphRange(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for sparse subsetting tests.');
        }

        $parser = new TrueTypeFontParser();
        $parsedFont = $parser->parse($fontPath);
        $fontProgram = $parser->fontProgram($fontPath);
        $tables = $this->tableDirectory($fontProgram);
        $head = $this->tableBytes($fontProgram, $tables, 'head');
        $maxp = $this->tableBytes($fontProgram, $tables, 'maxp');
        $loca = $this->tableBytes($fontProgram, $tables, 'loca');
        $indexToLocFormat = $this->s16($head, 50);
        $numGlyphs = $this->u16($maxp, 4);
        $originalLocaOffsets = $this->locaOffsets($loca, $numGlyphs, $indexToLocFormat);
        $glyphIds = array_values(array_unique(array_values($parsedFont->glyphMap)));
        sort($glyphIds);

        $selected = null;

        for ($index = 0; $index < count($glyphIds) - 1; $index++) {
            $left = $glyphIds[$index];
            $right = $glyphIds[$index + 1];

            if ($right - $left < 2) {
                continue;
            }

            for ($candidate = $left + 1; $candidate < $right; $candidate++) {
                if (($originalLocaOffsets[$candidate + 1] - $originalLocaOffsets[$candidate]) > 0) {
                    $selected = [$left, $right, $candidate];
                    break 2;
                }
            }
        }

        if ($selected === null) {
            $this->markTestSkipped('The local TrueType fixture does not expose a sparse glyph gap with non-empty intermediate glyph data.');
        }

        [$leftGlyphId, $rightGlyphId, $removedGlyphId] = $selected;
        $subsetResult = (new TrueTypeFontSubsetter())->subset($fontProgram, [$leftGlyphId, $rightGlyphId]);
        $this->assertTrue($subsetResult->subsetted);
        $this->assertLessThan(strlen($fontProgram), strlen($subsetResult->fontProgram));

        $subsetTables = $this->tableDirectory($subsetResult->fontProgram);
        $subsetHead = $this->tableBytes($subsetResult->fontProgram, $subsetTables, 'head');
        $subsetMaxp = $this->tableBytes($subsetResult->fontProgram, $subsetTables, 'maxp');
        $subsetLoca = $this->tableBytes($subsetResult->fontProgram, $subsetTables, 'loca');
        $subsetIndexToLocFormat = $this->s16($subsetHead, 50);
        $subsetNumGlyphs = $this->u16($subsetMaxp, 4);
        $subsetLocaOffsets = $this->locaOffsets($subsetLoca, $subsetNumGlyphs, $subsetIndexToLocFormat);

        $this->assertSame($rightGlyphId + 1, $subsetNumGlyphs);
        $this->assertGreaterThan(0, $subsetLocaOffsets[$leftGlyphId + 1] - $subsetLocaOffsets[$leftGlyphId]);
        $this->assertSame(0, $subsetLocaOffsets[$removedGlyphId + 1] - $subsetLocaOffsets[$removedGlyphId]);
        $this->assertGreaterThan(0, $subsetLocaOffsets[$rightGlyphId + 1] - $subsetLocaOffsets[$rightGlyphId]);
    }

    public function testDenseSubsetRemapsCompositeGlyphIdsIntoCompactRange(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for dense subsetting tests.');
        }

        $parser = new TrueTypeFontParser();
        $parsedFont = $parser->parse($fontPath);
        $fontProgram = $parser->fontProgram($fontPath);
        $glyphIds = array_values(array_unique(array_values($parsedFont->glyphMap)));
        sort($glyphIds);
        $glyphIds = array_values(array_filter($glyphIds, static fn (int $glyphId): bool => $glyphId > 0));

        if (count($glyphIds) < 2) {
            $this->markTestSkipped('The local TrueType fixture does not expose enough glyphs for dense subsetting tests.');
        }

        $lowGlyphId = $glyphIds[0];
        $highGlyphId = $glyphIds[count($glyphIds) - 1];

        if ($highGlyphId <= $lowGlyphId + 1) {
            $this->markTestSkipped('The local TrueType fixture does not expose a sparse enough glyph range for dense remapping tests.');
        }

        $subsetResult = (new TrueTypeFontSubsetter())->subsetDense($fontProgram, [$lowGlyphId, $highGlyphId]);
        $this->assertTrue($subsetResult->subsetted);
        $this->assertSame(0, $subsetResult->mappedGlyphId(0));
        $this->assertLessThan($highGlyphId, $subsetResult->mappedGlyphId($highGlyphId));
        $this->assertSame(1, $subsetResult->mappedGlyphId($lowGlyphId));

        $subsetTables = $this->tableDirectory($subsetResult->fontProgram);
        $subsetMaxp = $this->tableBytes($subsetResult->fontProgram, $subsetTables, 'maxp');
        $subsetNumGlyphs = $this->u16($subsetMaxp, 4);

        $this->assertSame(count($subsetResult->glyphIdMap), $subsetNumGlyphs);
        $this->assertLessThan($highGlyphId + 1, $subsetNumGlyphs);
    }

    public function testDenseSubsetWithCmapPreservesCharacterMappingForSimplePath(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for dense simple-path subsetting tests.');
        }

        $parser = new TrueTypeFontParser();
        $parsedFont = $parser->parse($fontPath);
        $fontProgram = $parser->fontProgram($fontPath);
        $codePointToGlyphId = [];

        foreach ([32, 65, 111] as $codePoint) {
            $glyphId = $parsedFont->glyphIdForCodePoint($codePoint);

            if ($glyphId === null || $glyphId === 0) {
                $this->markTestSkipped('The local TrueType fixture does not expose the basic Latin glyphs needed for simple-path dense subsetting tests.');
            }

            $codePointToGlyphId[$codePoint] = $glyphId;
        }

        $subsetResult = (new TrueTypeFontSubsetter())->subsetDenseWithCmap($fontProgram, $codePointToGlyphId);
        $this->assertTrue($subsetResult->subsetted);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'ptf');

        if ($temporaryFile === false) {
            $this->fail('Unable to allocate a temporary file for subset font parsing.');
        }

        file_put_contents($temporaryFile, $subsetResult->fontProgram);

        try {
            $subsetParsedFont = $parser->parse($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }

        foreach ($codePointToGlyphId as $codePoint => $originalGlyphId) {
            $this->assertSame(
                $subsetResult->mappedGlyphId($originalGlyphId),
                $subsetParsedFont->glyphIdForCodePoint($codePoint),
            );
        }
    }

    public function testCanRewriteSubsetFontInternalPostScriptName(): void
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        if ($fontPath === '' || !is_file($fontPath)) {
            $this->markTestSkipped('A local TrueType font is required for subset name rewriting tests.');
        }

        $parser = new TrueTypeFontParser();
        $parsedFont = $parser->parse($fontPath);
        $fontProgram = $parser->fontProgram($fontPath);
        $codePointToGlyphId = [];

        foreach ([32, 65, 111] as $codePoint) {
            $glyphId = $parsedFont->glyphIdForCodePoint($codePoint);

            if ($glyphId === null || $glyphId === 0) {
                $this->markTestSkipped('The local TrueType fixture does not expose the basic Latin glyphs needed for subset name rewriting tests.');
            }

            $codePointToGlyphId[$codePoint] = $glyphId;
        }

        $subsetter = new TrueTypeFontSubsetter();
        $subsetResult = $subsetter->subsetDenseWithCmap($fontProgram, $codePointToGlyphId);
        $taggedName = 'ABCDEF+' . ($parsedFont->postScriptName ?? 'SubsetFont');
        $renamedProgram = $subsetter->rewritePostScriptName($subsetResult->fontProgram, $taggedName);
        $temporaryFile = tempnam(sys_get_temp_dir(), 'ptf');

        if ($temporaryFile === false) {
            $this->fail('Unable to allocate a temporary file for subset name parsing.');
        }

        file_put_contents($temporaryFile, $renamedProgram);

        try {
            $renamedParsedFont = $parser->parse($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }

        $this->assertSame($taggedName, $renamedParsedFont->postScriptName);
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private function tableDirectory(string $fontProgram): array
    {
        $numTables = $this->u16($fontProgram, 4);
        $tables = [];
        $offset = 12;

        for ($index = 0; $index < $numTables; $index++) {
            $tag = substr($fontProgram, $offset, 4);
            $tables[$tag] = [
                'offset' => $this->u32($fontProgram, $offset + 8),
                'length' => $this->u32($fontProgram, $offset + 12),
            ];
            $offset += 16;
        }

        return $tables;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     */
    private function tableBytes(string $fontProgram, array $tables, string $tag): string
    {
        return substr($fontProgram, $tables[$tag]['offset'], $tables[$tag]['length']);
    }

    /**
     * @return list<int>
     */
    private function locaOffsets(string $loca, int $numGlyphs, int $indexToLocFormat): array
    {
        $offsets = [];

        for ($glyphId = 0; $glyphId <= $numGlyphs; $glyphId++) {
            $offsets[] = $indexToLocFormat === 0
                ? $this->u16($loca, $glyphId * 2) * 2
                : $this->u32($loca, $glyphId * 4);
        }

        return $offsets;
    }

    private function u16(string $bytes, int $offset): int
    {
        return unpack('n', substr($bytes, $offset, 2))[1];
    }

    private function s16(string $bytes, int $offset): int
    {
        $value = $this->u16($bytes, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(string $bytes, int $offset): int
    {
        return unpack('N', substr($bytes, $offset, 4))[1];
    }
}
