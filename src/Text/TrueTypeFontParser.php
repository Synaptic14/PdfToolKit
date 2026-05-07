<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Core\PdfException;

class TrueTypeFontParser
{
    /** @var array<string, ParsedTrueTypeFont> */
    private array $parsedFonts = [];

    /** @var array<string, string> */
    private array $fontPrograms = [];

    /** @var array<string, int> */
    private array $faceCounts = [];

    public function parse(string $path, int $faceIndex = 0): ParsedTrueTypeFont
    {
        $key = $this->fontKey($path, $faceIndex);

        return $this->parsedFonts[$key]
            ??= $this->parseFontProgram($this->fontProgram($path, $faceIndex));
    }

    public function faceCount(string $path): int
    {
        $key = $this->pathKey($path);

        if (isset($this->faceCounts[$key])) {
            return $this->faceCounts[$key];
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new PdfException(sprintf('Unable to read TrueType font file: %s', $path));
        }

        return $this->faceCounts[$key] = substr($bytes, 0, 4) === 'ttcf'
            ? $this->u32($bytes, 8)
            : 1;
    }

    public function fontProgram(string $path, int $faceIndex = 0): string
    {
        $key = $this->fontKey($path, $faceIndex);

        if (isset($this->fontPrograms[$key])) {
            return $this->fontPrograms[$key];
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new PdfException(sprintf('Unable to read TrueType font file: %s', $path));
        }

        if (substr($bytes, 0, 4) !== 'ttcf') {
            return $this->fontPrograms[$key] = $bytes;
        }

        return $this->fontPrograms[$key] = $this->extractStandaloneSfnt($bytes, $faceIndex);
    }

    private function fontKey(string $path, int $faceIndex): string
    {
        return $this->pathKey($path) . '#' . $faceIndex;
    }

    private function pathKey(string $path): string
    {
        $resolvedPath = realpath($path);

        return strtolower($resolvedPath !== false ? $resolvedPath : $path);
    }

    private function parseFontProgram(string $bytes): ParsedTrueTypeFont
    {
        $sfntOffset = 0;
        $tables = $this->readTableDirectory($bytes, $sfntOffset);
        $head = $this->table($bytes, $tables, 'head');
        $hhea = $this->table($bytes, $tables, 'hhea');
        $maxp = $this->table($bytes, $tables, 'maxp');
        $hmtx = $this->table($bytes, $tables, 'hmtx');
        $cmap = $this->table($bytes, $tables, 'cmap');
        $os2 = $this->optionalTable($bytes, $tables, 'OS/2');
        $post = $this->optionalTable($bytes, $tables, 'post');
        $name = $this->optionalTable($bytes, $tables, 'name');
        $kern = $this->optionalTable($bytes, $tables, 'kern');
        $gpos = $this->optionalTable($bytes, $tables, 'GPOS');
        $gsub = $this->optionalTable($bytes, $tables, 'GSUB');

        $unitsPerEm = $this->u16($head, 18);
        $xMin = $this->s16($head, 36);
        $yMin = $this->s16($head, 38);
        $xMax = $this->s16($head, 40);
        $yMax = $this->s16($head, 42);
        $macStyle = $this->u16($head, 44);
        $ascent = $this->s16($hhea, 4);
        $descent = $this->s16($hhea, 6);
        $lineGap = $this->s16($hhea, 8);
        $numberOfHMetrics = $this->u16($hhea, 34);
        $numGlyphs = $this->u16($maxp, 4);
        $advanceWidths = $this->parseAdvanceWidths($hmtx, $numberOfHMetrics, $numGlyphs);
        $glyphMap = $this->parseCmap($cmap);
        $glyphCodePoints = $this->reverseGlyphMap($glyphMap);
        $kerningPairs = array_replace(
            $kern !== null ? $this->parseKerningPairs($kern) : [],
            $gpos !== null ? $this->parseGposKerningPairs($gpos) : [],
        );
        $singleSubstitutions = $gsub !== null ? $this->parseSingleSubstitutions($gsub) : [];
        $alternateSubstitutions = $gsub !== null ? $this->parseAlternateSubstitutions($gsub) : [];
        $multipleSubstitutions = $gsub !== null ? $this->parseMultipleSubstitutions($gsub) : [];
        $ligatureSubstitutions = $gsub !== null ? $this->parseLigatureSubstitutions($gsub) : [];
        $postScriptName = $name !== null ? $this->parsePostScriptName($name) : null;
        $weightClass = $os2 !== null && strlen($os2) >= 6 ? $this->u16($os2, 4) : 400;
        $fsType = $os2 !== null && strlen($os2) >= 10 ? $this->u16($os2, 8) : 0;
        $xHeight = $os2 !== null && strlen($os2) >= 88 ? $this->s16($os2, 86) : 0;
        $capHeight = $os2 !== null && strlen($os2) >= 90 ? $this->s16($os2, 88) : $ascent;
        $italicAngle = $post !== null && strlen($post) >= 8 ? $this->fixed32($post, 4) : 0.0;
        $isFixedPitch = $post !== null && strlen($post) >= 16 ? $this->u32($post, 12) !== 0 : false;
        $isItalic = ($macStyle & 0x0002) === 0x0002 || $italicAngle != 0.0;
        $isBold = ($macStyle & 0x0001) === 0x0001;

        return new ParsedTrueTypeFont(
            postScriptName: $postScriptName,
            unitsPerEm: $unitsPerEm,
            ascent: $ascent,
            descent: $descent,
            lineGap: $lineGap,
            fontBBox: [$xMin, $yMin, $xMax, $yMax],
            capHeight: $capHeight > 0 ? $capHeight : $ascent,
            xHeight: $xHeight > 0 ? $xHeight : (int) round(($capHeight > 0 ? $capHeight : $ascent) * 0.7),
            weightClass: $weightClass,
            fsType: $fsType,
            italicAngle: $italicAngle,
            isFixedPitch: $isFixedPitch,
            isItalic: $isItalic,
            isBold: $isBold,
            glyphMap: $glyphMap,
            glyphCodePoints: $glyphCodePoints,
            advanceWidths: $advanceWidths,
            kerningPairs: $kerningPairs,
            singleSubstitutions: $singleSubstitutions,
            alternateSubstitutions: $alternateSubstitutions,
            multipleSubstitutions: $multipleSubstitutions,
            ligatureSubstitutions: $ligatureSubstitutions,
        );
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private function readTableDirectory(string $bytes, int $sfntOffset): array
    {
        $numTables = $this->u16($bytes, $sfntOffset + 4);
        $tables = [];
        $offset = $sfntOffset + 12;

        for ($i = 0; $i < $numTables; $i++) {
            $tag = substr($bytes, $offset, 4);
            $tables[$tag] = [
                'offset' => $this->u32($bytes, $offset + 8),
                'length' => $this->u32($bytes, $offset + 12),
            ];
            $offset += 16;
        }

        return $tables;
    }

    private function extractStandaloneSfnt(string $bytes, int $faceIndex): string
    {
        $numFonts = $this->u32($bytes, 8);

        if ($numFonts < 1) {
            throw new PdfException('TrueType Collection does not contain any font faces.');
        }

        if ($faceIndex < 0 || $faceIndex >= $numFonts) {
            throw new PdfException(sprintf('TrueType Collection face index %d is out of range.', $faceIndex));
        }

        $sfntOffset = $this->u32($bytes, 12 + ($faceIndex * 4));
        $offsetTable = substr($bytes, $sfntOffset, 12);
        $numTables = $this->u16($bytes, $sfntOffset + 4);
        $directoryOffset = $sfntOffset + 12;
        $nextOffset = 12 + ($numTables * 16);
        $tableRecords = [];
        $tableData = [];

        for ($i = 0; $i < $numTables; $i++) {
            $recordOffset = $directoryOffset + ($i * 16);
            $tag = substr($bytes, $recordOffset, 4);
            $length = $this->u32($bytes, $recordOffset + 12);
            $sourceOffset = $this->u32($bytes, $recordOffset + 8);
            $data = substr($bytes, $sourceOffset, $length);
            $paddedData = $data . str_repeat("\x00", (4 - ($length % 4)) % 4);

            $tableRecords[] = [
                'tag' => $tag,
                'offset' => $nextOffset,
                'length' => $length,
            ];
            $tableData[] = $paddedData;
            $nextOffset += strlen($paddedData);
        }

        $output = $offsetTable;

        foreach ($tableRecords as $index => $record) {
            $output .= $record['tag']
                . pack('N', $this->tableChecksum(substr($tableData[$index], 0, $record['length'])))
                . pack('N', $record['offset'])
                . pack('N', $record['length']);
        }

        foreach ($tableData as $data) {
            $output .= $data;
        }

        $headTableIndex = array_search('head', array_map(
            static fn (array $record): string => $record['tag'],
            $tableRecords,
        ), true);

        if ($headTableIndex !== false) {
            $headOffset = $tableRecords[$headTableIndex]['offset'];
            $output = substr_replace($output, "\x00\x00\x00\x00", $headOffset + 8, 4);
            $adjustment = (0xB1B0AFBA - $this->tableChecksum($output)) & 0xFFFFFFFF;
            $output = substr_replace($output, pack('N', $adjustment), $headOffset + 8, 4);
        }

        return $output;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     */
    private function table(string $bytes, array $tables, string $tag): string
    {
        if (!isset($tables[$tag])) {
            throw new PdfException(sprintf('TrueType font is missing required "%s" table.', $tag));
        }

        return substr($bytes, $tables[$tag]['offset'], $tables[$tag]['length']);
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     */
    private function optionalTable(string $bytes, array $tables, string $tag): ?string
    {
        if (!isset($tables[$tag])) {
            return null;
        }

        return substr($bytes, $tables[$tag]['offset'], $tables[$tag]['length']);
    }

    /**
     * @return array<int, int>
     */
    private function parseAdvanceWidths(string $hmtx, int $numberOfHMetrics, int $numGlyphs): array
    {
        $widths = [];
        $lastAdvanceWidth = 0;
        $offset = 0;

        for ($glyphId = 0; $glyphId < $numGlyphs; $glyphId++) {
            if ($glyphId < $numberOfHMetrics) {
                $lastAdvanceWidth = $this->u16($hmtx, $offset);
                $offset += 4;
            } else {
                $offset += 2;
            }

            $widths[$glyphId] = $lastAdvanceWidth;
        }

        return $widths;
    }

    /**
     * @return array<int, int>
     */
    private function parseCmap(string $cmap): array
    {
        $numTables = $this->u16($cmap, 2);
        $chosenOffset = null;
        $chosenFormat = null;

        for ($i = 0; $i < $numTables; $i++) {
            $recordOffset = 4 + ($i * 8);
            $platformId = $this->u16($cmap, $recordOffset);
            $encodingId = $this->u16($cmap, $recordOffset + 2);
            $subtableOffset = $this->u32($cmap, $recordOffset + 4);
            $format = $this->u16($cmap, $subtableOffset);

            if ($platformId === 3 && $encodingId === 10 && $format === 12) {
                $chosenOffset = $subtableOffset;
                $chosenFormat = 12;
                break;
            }

            if ($chosenOffset === null && (($platformId === 3 && $encodingId === 1) || $platformId === 0) && $format === 4) {
                $chosenOffset = $subtableOffset;
                $chosenFormat = 4;
            }
        }

        if ($chosenOffset === null || $chosenFormat === null) {
            throw new PdfException('TrueType font does not contain a supported Unicode cmap.');
        }

        return $chosenFormat === 12
            ? $this->parseCmapFormat12($cmap, $chosenOffset)
            : $this->parseCmapFormat4($cmap, $chosenOffset);
    }

    /**
     * @return array<int, int>
     */
    private function parseCmapFormat12(string $cmap, int $offset): array
    {
        $numGroups = $this->u32($cmap, $offset + 12);
        $map = [];
        $groupOffset = $offset + 16;

        for ($i = 0; $i < $numGroups; $i++) {
            $startCharCode = $this->u32($cmap, $groupOffset);
            $endCharCode = $this->u32($cmap, $groupOffset + 4);
            $startGlyphId = $this->u32($cmap, $groupOffset + 8);

            for ($codePoint = $startCharCode; $codePoint <= $endCharCode; $codePoint++) {
                $map[$codePoint] = $startGlyphId + ($codePoint - $startCharCode);
            }

            $groupOffset += 12;
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function parseCmapFormat4(string $cmap, int $offset): array
    {
        $segCount = intdiv($this->u16($cmap, $offset + 6), 2);
        $endCodeOffset = $offset + 14;
        $startCodeOffset = $endCodeOffset + ($segCount * 2) + 2;
        $idDeltaOffset = $startCodeOffset + ($segCount * 2);
        $idRangeOffsetOffset = $idDeltaOffset + ($segCount * 2);
        $glyphArrayOffset = $idRangeOffsetOffset + ($segCount * 2);
        $map = [];

        for ($segment = 0; $segment < $segCount; $segment++) {
            $endCode = $this->u16($cmap, $endCodeOffset + ($segment * 2));
            $startCode = $this->u16($cmap, $startCodeOffset + ($segment * 2));
            $idDelta = $this->s16($cmap, $idDeltaOffset + ($segment * 2));
            $idRangeOffset = $this->u16($cmap, $idRangeOffsetOffset + ($segment * 2));

            for ($codePoint = $startCode; $codePoint <= $endCode; $codePoint++) {
                if ($codePoint === 0xFFFF) {
                    continue;
                }

                if ($idRangeOffset === 0) {
                    $glyphId = ($codePoint + $idDelta) & 0xFFFF;
                } else {
                    $glyphIndexOffset = $idRangeOffsetOffset + ($segment * 2) + $idRangeOffset + (($codePoint - $startCode) * 2);

                    if ($glyphIndexOffset < $glyphArrayOffset || $glyphIndexOffset + 2 > strlen($cmap)) {
                        continue;
                    }

                    $glyphId = $this->u16($cmap, $glyphIndexOffset);

                    if ($glyphId !== 0) {
                        $glyphId = ($glyphId + $idDelta) & 0xFFFF;
                    }
                }

                if ($glyphId !== 0) {
                    $map[$codePoint] = $glyphId;
                }
            }
        }

        return $map;
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

    private function fixed32(string $bytes, int $offset): float
    {
        $raw = $this->u32($bytes, $offset);

        if ($raw >= 0x80000000) {
            $raw -= 0x100000000;
        }

        return $raw / 65536.0;
    }

    /**
     * @return array<string, int>
     */
    private function parseKerningPairs(string $kern): array
    {
        if (strlen($kern) < 4) {
            return [];
        }

        $subtableCount = $this->u16($kern, 2);
        $offset = 4;
        $pairs = [];

        for ($index = 0; $index < $subtableCount; $index++) {
            if ($offset + 6 > strlen($kern)) {
                break;
            }

            $length = $this->u16($kern, $offset + 2);
            $coverage = $this->u16($kern, $offset + 4);
            $format = ($coverage >> 8) & 0xFF;
            $horizontal = ($coverage & 0x0001) === 0x0001;

            if ($length < 14 || $offset + $length > strlen($kern)) {
                break;
            }

            if ($format === 0 && $horizontal) {
                $pairCount = $this->u16($kern, $offset + 6);
                $pairOffset = $offset + 14;

                for ($pairIndex = 0; $pairIndex < $pairCount; $pairIndex++) {
                    if ($pairOffset + 6 > $offset + $length) {
                        break;
                    }

                    $left = $this->u16($kern, $pairOffset);
                    $right = $this->u16($kern, $pairOffset + 2);
                    $value = $this->s16($kern, $pairOffset + 4);

                    if ($value !== 0) {
                        $pairs[$left . ':' . $right] = $value;
                    }

                    $pairOffset += 6;
                }
            }

            $offset += $length;
        }

        return $pairs;
    }

    private function parsePostScriptName(string $nameTable): ?string
    {
        if (strlen($nameTable) < 6) {
            return null;
        }

        $count = $this->u16($nameTable, 2);
        $stringOffset = $this->u16($nameTable, 4);
        $best = null;
        $fallback = null;

        for ($index = 0; $index < $count; $index++) {
            $recordOffset = 6 + ($index * 12);

            if ($recordOffset + 12 > strlen($nameTable)) {
                break;
            }

            $platformId = $this->u16($nameTable, $recordOffset);
            $encodingId = $this->u16($nameTable, $recordOffset + 2);
            $nameId = $this->u16($nameTable, $recordOffset + 6);
            $length = $this->u16($nameTable, $recordOffset + 8);
            $offset = $this->u16($nameTable, $recordOffset + 10);

            if (!in_array($nameId, [6, 4, 1], true)) {
                continue;
            }

            $stringStart = $stringOffset + $offset;
            $stringBytes = substr($nameTable, $stringStart, $length);

            if ($stringBytes === '') {
                continue;
            }

            $decoded = $this->decodeNameString($stringBytes, $platformId, $encodingId);

            if ($decoded === null || $decoded === '') {
                continue;
            }

            if ($nameId === 6) {
                $best = $decoded;
                break;
            }

            $fallback ??= $decoded;
        }

        $value = $best ?? $fallback;

        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9_+-]+/', '-', trim($value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<int, int> $glyphMap
     * @return array<int, int>
     */
    private function reverseGlyphMap(array $glyphMap): array
    {
        $glyphCodePoints = [];
        ksort($glyphMap);

        foreach ($glyphMap as $codePoint => $glyphId) {
            $glyphCodePoints[$glyphId] ??= $codePoint;
        }

        return $glyphCodePoints;
    }

    /**
     * @return array<string, int>
     */
    private function parseLigatureSubstitutions(string $gsub): array
    {
        if (strlen($gsub) < 10) {
            return [];
        }

        $featureListOffset = $this->u16($gsub, 6);
        $lookupListOffset = $this->u16($gsub, 8);
        $lookupIndices = $this->featureLookupIndices($gsub, $featureListOffset, ['liga', 'rlig', 'clig', 'dlig', 'hlig', 'calt', 'rclt']);

        if ($lookupIndices === []) {
            return [];
        }

        return $this->parseLigatureLookups($gsub, $lookupListOffset, $lookupIndices);
    }

    /**
     * @return array<int, int>
     */
    private function parseSingleSubstitutions(string $gsub): array
    {
        if (strlen($gsub) < 10) {
            return [];
        }

        $featureListOffset = $this->u16($gsub, 6);
        $lookupListOffset = $this->u16($gsub, 8);
        $lookupIndices = $this->featureLookupIndices($gsub, $featureListOffset, $this->singleSubstitutionFeatureTags());

        if ($lookupIndices === []) {
            return [];
        }

        return $this->parseSingleSubstitutionLookups($gsub, $lookupListOffset, $lookupIndices);
    }

    /**
     * @return array<int, list<int>>
     */
    private function parseAlternateSubstitutions(string $gsub): array
    {
        if (strlen($gsub) < 10) {
            return [];
        }

        $featureListOffset = $this->u16($gsub, 6);
        $lookupListOffset = $this->u16($gsub, 8);
        $lookupIndices = $this->featureLookupIndices($gsub, $featureListOffset, $this->alternateSubstitutionFeatureTags());

        if ($lookupIndices === []) {
            return [];
        }

        return $this->parseAlternateSubstitutionLookups($gsub, $lookupListOffset, $lookupIndices);
    }

    /**
     * @return array<int, list<int>>
     */
    private function parseMultipleSubstitutions(string $gsub): array
    {
        if (strlen($gsub) < 10) {
            return [];
        }

        $featureListOffset = $this->u16($gsub, 6);
        $lookupListOffset = $this->u16($gsub, 8);
        $lookupIndices = $this->featureLookupIndices($gsub, $featureListOffset, $this->multipleSubstitutionFeatureTags());

        if ($lookupIndices === []) {
            return [];
        }

        return $this->parseMultipleSubstitutionLookups($gsub, $lookupListOffset, $lookupIndices);
    }

    /**
     * @return array<string, int>
     */
    private function parseGposKerningPairs(string $gpos): array
    {
        if (strlen($gpos) < 10) {
            return [];
        }

        $featureListOffset = $this->u16($gpos, 6);
        $lookupListOffset = $this->u16($gpos, 8);
        $lookupIndices = $this->featureLookupIndices($gpos, $featureListOffset, ['kern']);

        if ($lookupIndices === []) {
            return [];
        }

        return $this->parsePairPositioningLookups($gpos, $lookupListOffset, $lookupIndices);
    }

    /**
     * @return list<int>
     */
    private function ligaLookupIndices(string $gsub, int $featureListOffset): array
    {
        return $this->featureLookupIndices($gsub, $featureListOffset, ['liga']);
    }

    /**
     * @return list<string>
     */
    private function singleSubstitutionFeatureTags(): array
    {
        return [
            'ccmp', 'rlig', 'locl', 'rvrn', 'smcp', 'c2sc', 'case', 'titl', 'unic',
            'fwid', 'hwid', 'twid', 'qwid', 'pwid', 'pnum', 'tnum',
            'lnum', 'onum', 'sinf', 'subs', 'sups', 'ordn', 'numr', 'dnom', 'zero',
            'vert', 'vrt2', 'ruby', 'hkna', 'vkna',
        ];
    }

    /**
     * @return list<string>
     */
    private function alternateSubstitutionFeatureTags(): array
    {
        $tags = ['salt', 'aalt', 'nalt', 'hist', 'swsh', 'cswh', 'ornm', 'rand', 'jp78', 'jp83', 'jp90', 'jp04', 'hojo', 'nlck'];

        for ($index = 1; $index <= 20; $index++) {
            $tags[] = sprintf('ss%02d', $index);
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    private function multipleSubstitutionFeatureTags(): array
    {
        return ['ccmp', 'locl', 'rvrn', 'calt', 'frac', 'afrc'];
    }

    /**
     * @param list<string> $featureTags
     * @return list<int>
     */
    private function featureLookupIndices(string $table, int $featureListOffset, array $featureTags): array
    {
        if ($featureListOffset <= 0 || $featureListOffset + 2 > strlen($table)) {
            return [];
        }

        $featureCount = $this->u16($table, $featureListOffset);
        $lookupIndices = [];

        for ($index = 0; $index < $featureCount; $index++) {
            $recordOffset = $featureListOffset + 2 + ($index * 6);

            if ($recordOffset + 6 > strlen($table)) {
                break;
            }

            $tag = substr($table, $recordOffset, 4);

            if (!in_array($tag, $featureTags, true)) {
                continue;
            }

            $featureOffset = $featureListOffset + $this->u16($table, $recordOffset + 4);

            if ($featureOffset + 4 > strlen($table)) {
                continue;
            }

            $lookupCount = $this->u16($table, $featureOffset + 2);

            for ($lookupIndex = 0; $lookupIndex < $lookupCount; $lookupIndex++) {
                $lookupIndices[] = $this->u16($table, $featureOffset + 4 + ($lookupIndex * 2));
            }
        }

        $lookupIndices = array_values(array_unique($lookupIndices));
        sort($lookupIndices);

        return $lookupIndices;
    }

    /**
     * @param list<int> $lookupIndices
     * @return array<string, int>
     */
    private function parseLigatureLookups(string $gsub, int $lookupListOffset, array $lookupIndices): array
    {
        if ($lookupListOffset <= 0 || $lookupListOffset + 2 > strlen($gsub)) {
            return [];
        }

        $lookupCount = $this->u16($gsub, $lookupListOffset);
        $substitutions = [];

        foreach ($lookupIndices as $lookupIndex) {
            if ($lookupIndex >= $lookupCount) {
                continue;
            }

            $lookupOffsetPointer = $lookupListOffset + 2 + ($lookupIndex * 2);

            if ($lookupOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $lookupOffset = $lookupListOffset + $this->u16($gsub, $lookupOffsetPointer);

            if ($lookupOffset + 6 > strlen($gsub)) {
                continue;
            }

            $lookupType = $this->u16($gsub, $lookupOffset);

            if ($lookupType !== 4) {
                continue;
            }

            $subtableCount = $this->u16($gsub, $lookupOffset + 4);

            for ($subtableIndex = 0; $subtableIndex < $subtableCount; $subtableIndex++) {
                $subtableOffsetPointer = $lookupOffset + 6 + ($subtableIndex * 2);

                if ($subtableOffsetPointer + 2 > strlen($gsub)) {
                    continue;
                }

                $subtableOffset = $lookupOffset + $this->u16($gsub, $subtableOffsetPointer);
                $substitutions += $this->parseLigatureSubtable($gsub, $subtableOffset);
            }
        }

        return $substitutions;
    }

    /**
     * @param list<int> $lookupIndices
     * @return array<string, int>
     */
    private function parsePairPositioningLookups(string $gpos, int $lookupListOffset, array $lookupIndices): array
    {
        if ($lookupListOffset <= 0 || $lookupListOffset + 2 > strlen($gpos)) {
            return [];
        }

        $lookupCount = $this->u16($gpos, $lookupListOffset);
        $pairs = [];

        foreach ($lookupIndices as $lookupIndex) {
            if ($lookupIndex >= $lookupCount) {
                continue;
            }

            $lookupOffsetPointer = $lookupListOffset + 2 + ($lookupIndex * 2);

            if ($lookupOffsetPointer + 2 > strlen($gpos)) {
                continue;
            }

            $lookupOffset = $lookupListOffset + $this->u16($gpos, $lookupOffsetPointer);

            if ($lookupOffset + 6 > strlen($gpos) || $this->u16($gpos, $lookupOffset) !== 2) {
                continue;
            }

            $subtableCount = $this->u16($gpos, $lookupOffset + 4);

            for ($subtableIndex = 0; $subtableIndex < $subtableCount; $subtableIndex++) {
                $subtableOffsetPointer = $lookupOffset + 6 + ($subtableIndex * 2);

                if ($subtableOffsetPointer + 2 > strlen($gpos)) {
                    continue;
                }

                $subtableOffset = $lookupOffset + $this->u16($gpos, $subtableOffsetPointer);

                if ($subtableOffset + 2 > strlen($gpos)) {
                    continue;
                }

                $pairs += match ($this->u16($gpos, $subtableOffset)) {
                    1 => $this->parsePairPosFormat1($gpos, $subtableOffset),
                    2 => $this->parsePairPosFormat2($gpos, $subtableOffset),
                    default => [],
                };
            }
        }

        return $pairs;
    }

    /**
     * @param list<int> $lookupIndices
     * @return array<int, int>
     */
    private function parseSingleSubstitutionLookups(string $gsub, int $lookupListOffset, array $lookupIndices): array
    {
        if ($lookupListOffset <= 0 || $lookupListOffset + 2 > strlen($gsub)) {
            return [];
        }

        $lookupCount = $this->u16($gsub, $lookupListOffset);
        $substitutions = [];

        foreach ($lookupIndices as $lookupIndex) {
            if ($lookupIndex >= $lookupCount) {
                continue;
            }

            $lookupOffsetPointer = $lookupListOffset + 2 + ($lookupIndex * 2);

            if ($lookupOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $lookupOffset = $lookupListOffset + $this->u16($gsub, $lookupOffsetPointer);

            if ($lookupOffset + 6 > strlen($gsub) || $this->u16($gsub, $lookupOffset) !== 1) {
                continue;
            }

            $subtableCount = $this->u16($gsub, $lookupOffset + 4);

            for ($subtableIndex = 0; $subtableIndex < $subtableCount; $subtableIndex++) {
                $subtableOffsetPointer = $lookupOffset + 6 + ($subtableIndex * 2);

                if ($subtableOffsetPointer + 2 > strlen($gsub)) {
                    continue;
                }

                $subtableOffset = $lookupOffset + $this->u16($gsub, $subtableOffsetPointer);

                if ($subtableOffset + 4 > strlen($gsub)) {
                    continue;
                }

                $substitutions += match ($this->u16($gsub, $subtableOffset)) {
                    1 => $this->parseSingleSubstitutionFormat1($gsub, $subtableOffset),
                    2 => $this->parseSingleSubstitutionFormat2($gsub, $subtableOffset),
                    default => [],
                };
            }
        }

        return $substitutions;
    }

    /**
     * @param list<int> $lookupIndices
     * @return array<int, list<int>>
     */
    private function parseAlternateSubstitutionLookups(string $gsub, int $lookupListOffset, array $lookupIndices): array
    {
        if ($lookupListOffset <= 0 || $lookupListOffset + 2 > strlen($gsub)) {
            return [];
        }

        $lookupCount = $this->u16($gsub, $lookupListOffset);
        $substitutions = [];

        foreach ($lookupIndices as $lookupIndex) {
            if ($lookupIndex >= $lookupCount) {
                continue;
            }

            $lookupOffsetPointer = $lookupListOffset + 2 + ($lookupIndex * 2);

            if ($lookupOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $lookupOffset = $lookupListOffset + $this->u16($gsub, $lookupOffsetPointer);

            if ($lookupOffset + 6 > strlen($gsub) || $this->u16($gsub, $lookupOffset) !== 3) {
                continue;
            }

            $subtableCount = $this->u16($gsub, $lookupOffset + 4);

            for ($subtableIndex = 0; $subtableIndex < $subtableCount; $subtableIndex++) {
                $subtableOffsetPointer = $lookupOffset + 6 + ($subtableIndex * 2);

                if ($subtableOffsetPointer + 2 > strlen($gsub)) {
                    continue;
                }

                $subtableOffset = $lookupOffset + $this->u16($gsub, $subtableOffsetPointer);
                $substitutions += $this->parseAlternateSubstitutionSubtable($gsub, $subtableOffset);
            }
        }

        return $substitutions;
    }

    /**
     * @param list<int> $lookupIndices
     * @return array<int, list<int>>
     */
    private function parseMultipleSubstitutionLookups(string $gsub, int $lookupListOffset, array $lookupIndices): array
    {
        if ($lookupListOffset <= 0 || $lookupListOffset + 2 > strlen($gsub)) {
            return [];
        }

        $lookupCount = $this->u16($gsub, $lookupListOffset);
        $substitutions = [];

        foreach ($lookupIndices as $lookupIndex) {
            if ($lookupIndex >= $lookupCount) {
                continue;
            }

            $lookupOffsetPointer = $lookupListOffset + 2 + ($lookupIndex * 2);

            if ($lookupOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $lookupOffset = $lookupListOffset + $this->u16($gsub, $lookupOffsetPointer);

            if ($lookupOffset + 6 > strlen($gsub) || $this->u16($gsub, $lookupOffset) !== 2) {
                continue;
            }

            $subtableCount = $this->u16($gsub, $lookupOffset + 4);

            for ($subtableIndex = 0; $subtableIndex < $subtableCount; $subtableIndex++) {
                $subtableOffsetPointer = $lookupOffset + 6 + ($subtableIndex * 2);

                if ($subtableOffsetPointer + 2 > strlen($gsub)) {
                    continue;
                }

                $subtableOffset = $lookupOffset + $this->u16($gsub, $subtableOffsetPointer);
                $substitutions += $this->parseMultipleSubstitutionSubtable($gsub, $subtableOffset);
            }
        }

        return $substitutions;
    }

    /**
     * @return array<string, int>
     */
    private function parseLigatureSubtable(string $gsub, int $subtableOffset): array
    {
        if ($subtableOffset + 6 > strlen($gsub) || $this->u16($gsub, $subtableOffset) !== 1) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gsub, $subtableOffset + 2);
        $ligatureSetCount = $this->u16($gsub, $subtableOffset + 4);
        $firstGlyphIds = $this->parseCoverageGlyphs($gsub, $coverageOffset);

        if ($firstGlyphIds === [] || count($firstGlyphIds) !== $ligatureSetCount) {
            return [];
        }

        $substitutions = [];

        for ($setIndex = 0; $setIndex < $ligatureSetCount; $setIndex++) {
            $setOffsetPointer = $subtableOffset + 6 + ($setIndex * 2);

            if ($setOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $setOffset = $subtableOffset + $this->u16($gsub, $setOffsetPointer);

            if ($setOffset + 2 > strlen($gsub)) {
                continue;
            }

            $ligatureCount = $this->u16($gsub, $setOffset);
            $firstGlyphId = $firstGlyphIds[$setIndex];

            for ($ligatureIndex = 0; $ligatureIndex < $ligatureCount; $ligatureIndex++) {
                $ligatureOffsetPointer = $setOffset + 2 + ($ligatureIndex * 2);

                if ($ligatureOffsetPointer + 2 > strlen($gsub)) {
                    continue;
                }

                $ligatureOffset = $setOffset + $this->u16($gsub, $ligatureOffsetPointer);

                if ($ligatureOffset + 4 > strlen($gsub)) {
                    continue;
                }

                $ligatureGlyph = $this->u16($gsub, $ligatureOffset);
                $componentCount = $this->u16($gsub, $ligatureOffset + 2);
                $sequence = [$firstGlyphId];

                for ($componentIndex = 1; $componentIndex < $componentCount; $componentIndex++) {
                    $componentOffset = $ligatureOffset + 4 + (($componentIndex - 1) * 2);

                    if ($componentOffset + 2 > strlen($gsub)) {
                        continue 2;
                    }

                    $sequence[] = $this->u16($gsub, $componentOffset);
                }

                $substitutions[implode(':', $sequence)] = $ligatureGlyph;
            }
        }

        return $substitutions;
    }

    /**
     * @return array<string, int>
     */
    private function parsePairPosFormat1(string $gpos, int $subtableOffset): array
    {
        if ($subtableOffset + 10 > strlen($gpos)) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gpos, $subtableOffset + 2);
        $valueFormat1 = $this->u16($gpos, $subtableOffset + 4);
        $valueFormat2 = $this->u16($gpos, $subtableOffset + 6);
        $pairSetCount = $this->u16($gpos, $subtableOffset + 8);
        $firstGlyphIds = $this->parseCoverageGlyphs($gpos, $coverageOffset);

        if ($firstGlyphIds === [] || count($firstGlyphIds) !== $pairSetCount) {
            return [];
        }

        $pairs = [];
        $valueSize1 = $this->valueRecordSize($valueFormat1);
        $valueSize2 = $this->valueRecordSize($valueFormat2);

        for ($setIndex = 0; $setIndex < $pairSetCount; $setIndex++) {
            $pairSetOffsetPointer = $subtableOffset + 10 + ($setIndex * 2);

            if ($pairSetOffsetPointer + 2 > strlen($gpos)) {
                continue;
            }

            $pairSetOffset = $subtableOffset + $this->u16($gpos, $pairSetOffsetPointer);

            if ($pairSetOffset + 2 > strlen($gpos)) {
                continue;
            }

            $pairValueCount = $this->u16($gpos, $pairSetOffset);
            $pairOffset = $pairSetOffset + 2;
            $leftGlyphId = $firstGlyphIds[$setIndex];

            for ($pairIndex = 0; $pairIndex < $pairValueCount; $pairIndex++) {
                if ($pairOffset + 2 + $valueSize1 + $valueSize2 > strlen($gpos)) {
                    break;
                }

                $rightGlyphId = $this->u16($gpos, $pairOffset);
                $adjustment = $this->pairAdjustment(
                    substr($gpos, $pairOffset + 2, $valueSize1),
                    $valueFormat1,
                    substr($gpos, $pairOffset + 2 + $valueSize1, $valueSize2),
                    $valueFormat2,
                );

                if ($adjustment !== 0) {
                    $pairs[$leftGlyphId . ':' . $rightGlyphId] = $adjustment;
                }

                $pairOffset += 2 + $valueSize1 + $valueSize2;
            }
        }

        return $pairs;
    }

    /**
     * @return array<int, int>
     */
    private function parseSingleSubstitutionFormat1(string $gsub, int $subtableOffset): array
    {
        if ($subtableOffset + 6 > strlen($gsub)) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gsub, $subtableOffset + 2);
        $deltaGlyphId = $this->s16($gsub, $subtableOffset + 4);
        $coveredGlyphs = $this->parseCoverageGlyphs($gsub, $coverageOffset);
        $substitutions = [];

        foreach ($coveredGlyphs as $glyphId) {
            $substitutions[$glyphId] = ($glyphId + $deltaGlyphId) & 0xFFFF;
        }

        return $substitutions;
    }

    /**
     * @return array<int, int>
     */
    private function parseSingleSubstitutionFormat2(string $gsub, int $subtableOffset): array
    {
        if ($subtableOffset + 6 > strlen($gsub)) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gsub, $subtableOffset + 2);
        $glyphCount = $this->u16($gsub, $subtableOffset + 4);
        $coveredGlyphs = $this->parseCoverageGlyphs($gsub, $coverageOffset);

        if ($coveredGlyphs === [] || count($coveredGlyphs) !== $glyphCount) {
            return [];
        }

        $substitutions = [];

        for ($index = 0; $index < $glyphCount; $index++) {
            $substituteOffset = $subtableOffset + 6 + ($index * 2);

            if ($substituteOffset + 2 > strlen($gsub)) {
                break;
            }

            $substitutions[$coveredGlyphs[$index]] = $this->u16($gsub, $substituteOffset);
        }

        return $substitutions;
    }

    /**
     * @return array<int, list<int>>
     */
    private function parseAlternateSubstitutionSubtable(string $gsub, int $subtableOffset): array
    {
        if ($subtableOffset + 6 > strlen($gsub) || $this->u16($gsub, $subtableOffset) !== 1) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gsub, $subtableOffset + 2);
        $alternateSetCount = $this->u16($gsub, $subtableOffset + 4);
        $coveredGlyphs = $this->parseCoverageGlyphs($gsub, $coverageOffset);

        if ($coveredGlyphs === [] || count($coveredGlyphs) !== $alternateSetCount) {
            return [];
        }

        $substitutions = [];

        for ($index = 0; $index < $alternateSetCount; $index++) {
            $setOffsetPointer = $subtableOffset + 6 + ($index * 2);

            if ($setOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $setOffset = $subtableOffset + $this->u16($gsub, $setOffsetPointer);

            if ($setOffset + 2 > strlen($gsub)) {
                continue;
            }

            $glyphCount = $this->u16($gsub, $setOffset);
            $alternates = [];

            for ($glyphIndex = 0; $glyphIndex < $glyphCount; $glyphIndex++) {
                $glyphOffset = $setOffset + 2 + ($glyphIndex * 2);

                if ($glyphOffset + 2 > strlen($gsub)) {
                    break;
                }

                $alternates[] = $this->u16($gsub, $glyphOffset);
            }

            if ($alternates !== []) {
                $substitutions[$coveredGlyphs[$index]] = $alternates;
            }
        }

        return $substitutions;
    }

    /**
     * @return array<int, list<int>>
     */
    private function parseMultipleSubstitutionSubtable(string $gsub, int $subtableOffset): array
    {
        if ($subtableOffset + 6 > strlen($gsub) || $this->u16($gsub, $subtableOffset) !== 1) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gsub, $subtableOffset + 2);
        $sequenceCount = $this->u16($gsub, $subtableOffset + 4);
        $coveredGlyphs = $this->parseCoverageGlyphs($gsub, $coverageOffset);

        if ($coveredGlyphs === [] || count($coveredGlyphs) !== $sequenceCount) {
            return [];
        }

        $substitutions = [];

        for ($index = 0; $index < $sequenceCount; $index++) {
            $sequenceOffsetPointer = $subtableOffset + 6 + ($index * 2);

            if ($sequenceOffsetPointer + 2 > strlen($gsub)) {
                continue;
            }

            $sequenceOffset = $subtableOffset + $this->u16($gsub, $sequenceOffsetPointer);

            if ($sequenceOffset + 2 > strlen($gsub)) {
                continue;
            }

            $glyphCount = $this->u16($gsub, $sequenceOffset);
            $sequence = [];

            for ($glyphIndex = 0; $glyphIndex < $glyphCount; $glyphIndex++) {
                $glyphOffset = $sequenceOffset + 2 + ($glyphIndex * 2);

                if ($glyphOffset + 2 > strlen($gsub)) {
                    break;
                }

                $sequence[] = $this->u16($gsub, $glyphOffset);
            }

            if ($sequence !== []) {
                $substitutions[$coveredGlyphs[$index]] = $sequence;
            }
        }

        return $substitutions;
    }

    /**
     * @return array<string, int>
     */
    private function parsePairPosFormat2(string $gpos, int $subtableOffset): array
    {
        if ($subtableOffset + 16 > strlen($gpos)) {
            return [];
        }

        $coverageOffset = $subtableOffset + $this->u16($gpos, $subtableOffset + 2);
        $valueFormat1 = $this->u16($gpos, $subtableOffset + 4);
        $valueFormat2 = $this->u16($gpos, $subtableOffset + 6);
        $classDef1Offset = $subtableOffset + $this->u16($gpos, $subtableOffset + 8);
        $classDef2Offset = $subtableOffset + $this->u16($gpos, $subtableOffset + 10);
        $class1Count = $this->u16($gpos, $subtableOffset + 12);
        $class2Count = $this->u16($gpos, $subtableOffset + 14);
        $coveredGlyphs = $this->parseCoverageGlyphs($gpos, $coverageOffset);

        if ($coveredGlyphs === [] || $class1Count === 0 || $class2Count === 0) {
            return [];
        }

        $classDef1 = $this->parseClassDef($gpos, $classDef1Offset);
        $classDef2 = $this->parseClassDef($gpos, $classDef2Offset);
        $valueSize1 = $this->valueRecordSize($valueFormat1);
        $valueSize2 = $this->valueRecordSize($valueFormat2);
        $class1RecordSize = $class2Count * ($valueSize1 + $valueSize2);
        $pairValueArrayOffset = $subtableOffset + 16;
        $rightGlyphIds = array_keys($classDef2);
        $maxRightGlyphId = $rightGlyphIds === [] ? 0 : max($rightGlyphIds);
        $pairs = [];

        foreach ($coveredGlyphs as $leftGlyphId) {
            $class1 = $classDef1[$leftGlyphId] ?? 0;

            if ($class1 >= $class1Count) {
                continue;
            }

            for ($rightGlyphId = 0; $rightGlyphId <= $maxRightGlyphId; $rightGlyphId++) {
                $class2 = $classDef2[$rightGlyphId] ?? 0;

                if ($class2 >= $class2Count) {
                    continue;
                }

                $pairValueOffset = $pairValueArrayOffset + ($class1 * $class1RecordSize) + ($class2 * ($valueSize1 + $valueSize2));

                if ($pairValueOffset + $valueSize1 + $valueSize2 > strlen($gpos)) {
                    continue;
                }

                $adjustment = $this->pairAdjustment(
                    substr($gpos, $pairValueOffset, $valueSize1),
                    $valueFormat1,
                    substr($gpos, $pairValueOffset + $valueSize1, $valueSize2),
                    $valueFormat2,
                );

                if ($adjustment !== 0) {
                    $pairs[$leftGlyphId . ':' . $rightGlyphId] = $adjustment;
                }
            }
        }

        return $pairs;
    }

    /**
     * @return list<int>
     */
    private function parseCoverageGlyphs(string $table, int $coverageOffset): array
    {
        if ($coverageOffset + 4 > strlen($table)) {
            return [];
        }

        $format = $this->u16($table, $coverageOffset);

        if ($format === 1) {
            $glyphCount = $this->u16($table, $coverageOffset + 2);
            $glyphIds = [];

            for ($index = 0; $index < $glyphCount; $index++) {
                $glyphOffset = $coverageOffset + 4 + ($index * 2);

                if ($glyphOffset + 2 > strlen($table)) {
                    break;
                }

                $glyphIds[] = $this->u16($table, $glyphOffset);
            }

            return $glyphIds;
        }

        if ($format === 2) {
            $rangeCount = $this->u16($table, $coverageOffset + 2);
            $glyphIds = [];

            for ($index = 0; $index < $rangeCount; $index++) {
                $rangeOffset = $coverageOffset + 4 + ($index * 6);

                if ($rangeOffset + 6 > strlen($table)) {
                    break;
                }

                $start = $this->u16($table, $rangeOffset);
                $end = $this->u16($table, $rangeOffset + 2);

                for ($glyphId = $start; $glyphId <= $end; $glyphId++) {
                    $glyphIds[] = $glyphId;
                }
            }

            return $glyphIds;
        }

        return [];
    }

    /**
     * @return array<int, int>
     */
    private function parseClassDef(string $table, int $classDefOffset): array
    {
        if ($classDefOffset + 4 > strlen($table)) {
            return [];
        }

        $format = $this->u16($table, $classDefOffset);

        if ($format === 1) {
            $startGlyphId = $this->u16($table, $classDefOffset + 2);
            $glyphCount = $this->u16($table, $classDefOffset + 4);
            $classes = [];

            for ($index = 0; $index < $glyphCount; $index++) {
                $valueOffset = $classDefOffset + 6 + ($index * 2);

                if ($valueOffset + 2 > strlen($table)) {
                    break;
                }

                $classes[$startGlyphId + $index] = $this->u16($table, $valueOffset);
            }

            return $classes;
        }

        if ($format === 2) {
            $rangeCount = $this->u16($table, $classDefOffset + 2);
            $classes = [];

            for ($index = 0; $index < $rangeCount; $index++) {
                $rangeOffset = $classDefOffset + 4 + ($index * 6);

                if ($rangeOffset + 6 > strlen($table)) {
                    break;
                }

                $startGlyphId = $this->u16($table, $rangeOffset);
                $endGlyphId = $this->u16($table, $rangeOffset + 2);
                $class = $this->u16($table, $rangeOffset + 4);

                for ($glyphId = $startGlyphId; $glyphId <= $endGlyphId; $glyphId++) {
                    $classes[$glyphId] = $class;
                }
            }

            return $classes;
        }

        return [];
    }

    private function valueRecordSize(int $valueFormat): int
    {
        $size = 0;

        foreach ([0x0001, 0x0002, 0x0004, 0x0008, 0x0010, 0x0020, 0x0040, 0x0080] as $bit) {
            if (($valueFormat & $bit) === $bit) {
                $size += 2;
            }
        }

        return $size;
    }

    private function pairAdjustment(string $valueRecord1, int $valueFormat1, string $valueRecord2, int $valueFormat2): int
    {
        return $this->valueRecordComponent($valueRecord1, $valueFormat1, 0x0004)
            + $this->valueRecordComponent($valueRecord2, $valueFormat2, 0x0001);
    }

    private function valueRecordComponent(string $valueRecord, int $valueFormat, int $targetBit): int
    {
        if (($valueFormat & $targetBit) !== $targetBit) {
            return 0;
        }

        $offset = 0;

        foreach ([0x0001, 0x0002, 0x0004, 0x0008, 0x0010, 0x0020, 0x0040, 0x0080] as $bit) {
            if (($valueFormat & $bit) !== $bit) {
                continue;
            }

            if ($bit === $targetBit) {
                return $this->s16($valueRecord, $offset);
            }

            $offset += 2;
        }

        return 0;
    }

    private function decodeNameString(string $bytes, int $platformId, int $encodingId): ?string
    {
        if ($platformId === 3 || $platformId === 0) {
            $decoded = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');

            return is_string($decoded) ? $decoded : null;
        }

        if ($platformId === 1) {
            if (function_exists('iconv')) {
                $decoded = @iconv('macintosh', 'UTF-8//IGNORE', $bytes);

                if (is_string($decoded) && $decoded !== '') {
                    return $decoded;
                }
            }

            return $bytes;
        }

        return $bytes;
    }

    private function tableChecksum(string $bytes): int
    {
        $padded = $bytes . str_repeat("\x00", (4 - (strlen($bytes) % 4)) % 4);
        $sum = 0;

        for ($offset = 0; $offset < strlen($padded); $offset += 4) {
            $sum = ($sum + $this->u32($padded, $offset)) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
