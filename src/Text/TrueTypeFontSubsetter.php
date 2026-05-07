<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Core\PdfException;

final class TrueTypeFontSubsetter
{
    /**
     * @param list<int> $requiredGlyphIds
     */
    public function subset(string $fontProgram, array $requiredGlyphIds): TrueTypeFontSubsetResult
    {
        if ($requiredGlyphIds === []) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        $tables = $this->readTableDirectory($fontProgram);
        $head = $this->table($fontProgram, $tables, 'head');
        $hhea = $this->table($fontProgram, $tables, 'hhea');
        $maxp = $this->table($fontProgram, $tables, 'maxp');
        $hmtx = $this->table($fontProgram, $tables, 'hmtx');
        $loca = $this->table($fontProgram, $tables, 'loca');
        $glyf = $this->table($fontProgram, $tables, 'glyf');

        $indexToLocFormat = $this->s16($head, 50);
        $numGlyphs = $this->u16($maxp, 4);
        $numberOfHMetrics = $this->u16($hhea, 34);
        $glyphOffsets = $this->parseLocaOffsets($loca, $numGlyphs, $indexToLocFormat);
        $requiredGlyphIds = $this->expandCompositeDependencies($glyf, $glyphOffsets, $requiredGlyphIds);
        $maxGlyphId = max($requiredGlyphIds);

        if ($maxGlyphId >= $numGlyphs) {
            throw new PdfException('TrueType subset required glyph is out of range.');
        }

        $subsetGlyphCount = $maxGlyphId + 1;

        if ($subsetGlyphCount >= $numGlyphs) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        [$subsetGlyf, $subsetLoca] = $this->buildSparseSubsetGlyphTables(
            $glyf,
            $glyphOffsets,
            array_fill_keys($requiredGlyphIds, true),
            $subsetGlyphCount,
            $indexToLocFormat,
        );
        [$subsetMetrics, $subsetNumberOfHMetrics] = $this->buildHmtxTable($hmtx, $subsetGlyphCount, $numberOfHMetrics);

        $maxp = substr_replace($maxp, pack('n', $subsetGlyphCount), 4, 2);
        $hhea = substr_replace($hhea, pack('n', $subsetNumberOfHMetrics), 34, 2);

        $tableData = [];

        foreach ($tables as $tag => $record) {
            $tableData[$tag] = substr($fontProgram, $record['offset'], $record['length']);
        }

        $tableData['glyf'] = $subsetGlyf;
        $tableData['loca'] = $subsetLoca;
        $tableData['hmtx'] = $subsetMetrics;
        $tableData['maxp'] = $maxp;
        $tableData['hhea'] = $hhea;

        return new TrueTypeFontSubsetResult(
            $this->buildSfnt($fontProgram, $tables, $tableData),
            true,
            array_combine($requiredGlyphIds, $requiredGlyphIds) ?: [],
        );
    }

    /**
     * @param list<int> $requiredGlyphIds
     */
    public function subsetDense(string $fontProgram, array $requiredGlyphIds): TrueTypeFontSubsetResult
    {
        if ($requiredGlyphIds === []) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        $tables = $this->readTableDirectory($fontProgram);
        $head = $this->table($fontProgram, $tables, 'head');
        $hhea = $this->table($fontProgram, $tables, 'hhea');
        $maxp = $this->table($fontProgram, $tables, 'maxp');
        $hmtx = $this->table($fontProgram, $tables, 'hmtx');
        $loca = $this->table($fontProgram, $tables, 'loca');
        $glyf = $this->table($fontProgram, $tables, 'glyf');

        $indexToLocFormat = $this->s16($head, 50);
        $numGlyphs = $this->u16($maxp, 4);
        $numberOfHMetrics = $this->u16($hhea, 34);
        $glyphOffsets = $this->parseLocaOffsets($loca, $numGlyphs, $indexToLocFormat);
        $requiredGlyphIds = $this->expandCompositeDependencies($glyf, $glyphOffsets, $requiredGlyphIds);

        if (count($requiredGlyphIds) >= $numGlyphs) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        return $this->buildDenseSubsetResult(
            $fontProgram,
            $tables,
            $hhea,
            $maxp,
            $hmtx,
            $glyf,
            $glyphOffsets,
            $requiredGlyphIds,
            $numberOfHMetrics,
            $indexToLocFormat,
        );
    }

    /**
     * @param array<int, int> $codePointToGlyphId
     */
    public function subsetDenseWithCmap(string $fontProgram, array $codePointToGlyphId): TrueTypeFontSubsetResult
    {
        if ($codePointToGlyphId === []) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        $tables = $this->readTableDirectory($fontProgram);
        $head = $this->table($fontProgram, $tables, 'head');
        $hhea = $this->table($fontProgram, $tables, 'hhea');
        $maxp = $this->table($fontProgram, $tables, 'maxp');
        $hmtx = $this->table($fontProgram, $tables, 'hmtx');
        $loca = $this->table($fontProgram, $tables, 'loca');
        $glyf = $this->table($fontProgram, $tables, 'glyf');

        $indexToLocFormat = $this->s16($head, 50);
        $numGlyphs = $this->u16($maxp, 4);
        $numberOfHMetrics = $this->u16($hhea, 34);
        $glyphOffsets = $this->parseLocaOffsets($loca, $numGlyphs, $indexToLocFormat);
        $requiredGlyphIds = $this->expandCompositeDependencies($glyf, $glyphOffsets, array_values($codePointToGlyphId));

        if (count($requiredGlyphIds) >= $numGlyphs) {
            return new TrueTypeFontSubsetResult($fontProgram, false);
        }

        $baseResult = $this->buildDenseSubsetResult(
            $fontProgram,
            $tables,
            $hhea,
            $maxp,
            $hmtx,
            $glyf,
            $glyphOffsets,
            $requiredGlyphIds,
            $numberOfHMetrics,
            $indexToLocFormat,
        );

        if (!$baseResult->subsetted) {
            return $baseResult;
        }

        $subsetTables = $this->readTableDirectory($baseResult->fontProgram);
        $tableData = [];

        foreach ($subsetTables as $tag => $record) {
            $tableData[$tag] = substr($baseResult->fontProgram, $record['offset'], $record['length']);
        }

        $remappedCodePointToGlyphId = [];

        foreach ($codePointToGlyphId as $codePoint => $glyphId) {
            $remappedCodePointToGlyphId[$codePoint] = $baseResult->mappedGlyphId($glyphId) ?? $glyphId;
        }

        $tableData['cmap'] = $this->buildSimpleUnicodeCmap($remappedCodePointToGlyphId);

        return new TrueTypeFontSubsetResult(
            $this->buildSfnt($baseResult->fontProgram, $subsetTables, $tableData),
            true,
            $baseResult->glyphIdMap,
        );
    }

    public function rewritePostScriptName(string $fontProgram, string $postScriptName): string
    {
        $tables = $this->readTableDirectory($fontProgram);

        if (!isset($tables['name'])) {
            return $fontProgram;
        }

        $tableData = [];

        foreach ($tables as $tag => $record) {
            $tableData[$tag] = substr($fontProgram, $record['offset'], $record['length']);
        }

        $rewritten = $this->rewriteNameTable($tableData['name'], $postScriptName);

        if ($rewritten === null) {
            return $fontProgram;
        }

        $tableData['name'] = $rewritten;

        return $this->buildSfnt($fontProgram, $tables, $tableData);
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     * @param list<int> $glyphOffsets
     * @param list<int> $requiredGlyphIds
     */
    private function buildDenseSubsetResult(
        string $fontProgram,
        array $tables,
        string $hhea,
        string $maxp,
        string $hmtx,
        string $glyf,
        array $glyphOffsets,
        array $requiredGlyphIds,
        int $numberOfHMetrics,
        int $indexToLocFormat,
    ): TrueTypeFontSubsetResult {
        $glyphIdMap = [];

        foreach ($requiredGlyphIds as $newGlyphId => $oldGlyphId) {
            $glyphIdMap[$oldGlyphId] = $newGlyphId;
        }

        [$subsetGlyf, $subsetLoca] = $this->buildDenseSubsetGlyphTables(
            $glyf,
            $glyphOffsets,
            $requiredGlyphIds,
            $glyphIdMap,
            $indexToLocFormat,
        );
        [$subsetMetrics, $subsetNumberOfHMetrics] = $this->buildDenseHmtxTable(
            $hmtx,
            $requiredGlyphIds,
            $numberOfHMetrics,
        );

        $maxp = substr_replace($maxp, pack('n', count($requiredGlyphIds)), 4, 2);
        $hhea = substr_replace($hhea, pack('n', $subsetNumberOfHMetrics), 34, 2);

        $tableData = [];

        foreach ($tables as $tag => $record) {
            $tableData[$tag] = substr($fontProgram, $record['offset'], $record['length']);
        }

        $tableData['glyf'] = $subsetGlyf;
        $tableData['loca'] = $subsetLoca;
        $tableData['hmtx'] = $subsetMetrics;
        $tableData['maxp'] = $maxp;
        $tableData['hhea'] = $hhea;

        return new TrueTypeFontSubsetResult(
            $this->buildSfnt($fontProgram, $tables, $tableData),
            true,
            $glyphIdMap,
        );
    }

    /**
     * @param array<int, int> $codePointToGlyphId
     */
    private function buildSimpleUnicodeCmap(array $codePointToGlyphId): string
    {
        ksort($codePointToGlyphId);
        $segmentCodePoints = array_keys($codePointToGlyphId);
        $segCount = count($segmentCodePoints) + 1;
        $segCountX2 = $segCount * 2;
        $searchPower = 1;

        while (($searchPower * 2) <= $segCount) {
            $searchPower *= 2;
        }

        $searchRange = $searchPower * 2;
        $entrySelector = 0;
        $probe = $searchPower;

        while ($probe > 1) {
            $probe = intdiv($probe, 2);
            $entrySelector++;
        }

        $rangeShift = $segCountX2 - $searchRange;
        $endCodes = '';
        $startCodes = '';
        $idDeltas = '';
        $idRangeOffsets = '';

        foreach ($segmentCodePoints as $codePoint) {
            $glyphId = $codePointToGlyphId[$codePoint];
            $endCodes .= pack('n', $codePoint);
            $startCodes .= pack('n', $codePoint);
            $idDeltas .= pack('n', ($glyphId - $codePoint) & 0xFFFF);
            $idRangeOffsets .= "\x00\x00";
        }

        $endCodes .= "\xFF\xFF";
        $startCodes .= "\xFF\xFF";
        $idDeltas .= "\x00\x01";
        $idRangeOffsets .= "\x00\x00";

        $subtableLength = 16 + strlen($endCodes) + 2 + strlen($startCodes) + strlen($idDeltas) + strlen($idRangeOffsets);
        $format4 = pack(
            'nnnnnnn',
            4,
            $subtableLength,
            0,
            $segCountX2,
            $searchRange,
            $entrySelector,
            $rangeShift
        ) . $endCodes
            . "\x00\x00"
            . $startCodes
            . $idDeltas
            . $idRangeOffsets;

        return pack('nn', 0, 1)
            . pack('nnN', 3, 1, 12)
            . $format4;
    }

    private function rewriteNameTable(string $nameTable, string $postScriptName): ?string
    {
        if (strlen($nameTable) < 6) {
            return null;
        }

        $format = $this->u16($nameTable, 0);

        if ($format !== 0) {
            return null;
        }

        $count = $this->u16($nameTable, 2);
        $stringOffset = $this->u16($nameTable, 4);
        $records = [];
        $stringStorage = '';
        $rewroteAny = false;

        for ($index = 0; $index < $count; $index++) {
            $recordOffset = 6 + ($index * 12);

            if ($recordOffset + 12 > strlen($nameTable)) {
                return null;
            }

            $platformId = $this->u16($nameTable, $recordOffset);
            $encodingId = $this->u16($nameTable, $recordOffset + 2);
            $languageId = $this->u16($nameTable, $recordOffset + 4);
            $nameId = $this->u16($nameTable, $recordOffset + 6);
            $length = $this->u16($nameTable, $recordOffset + 8);
            $offset = $this->u16($nameTable, $recordOffset + 10);
            $stringBytes = substr($nameTable, $stringOffset + $offset, $length);

            if ($nameId === 6) {
                $stringBytes = $this->encodeNameString($postScriptName, $platformId, $encodingId);
                $rewroteAny = true;
            }

            $records[] = [
                'platformId' => $platformId,
                'encodingId' => $encodingId,
                'languageId' => $languageId,
                'nameId' => $nameId,
                'length' => strlen($stringBytes),
                'offset' => strlen($stringStorage),
                'bytes' => $stringBytes,
            ];
            $stringStorage .= $stringBytes;
        }

        if (!$rewroteAny) {
            return null;
        }

        $output = pack('nnn', 0, $count, 6 + ($count * 12));

        foreach ($records as $record) {
            $output .= pack(
                'nnnnnn',
                $record['platformId'],
                $record['encodingId'],
                $record['languageId'],
                $record['nameId'],
                $record['length'],
                $record['offset'],
            );
        }

        return $output . $stringStorage;
    }

    private function encodeNameString(string $value, int $platformId, int $encodingId): string
    {
        if ($platformId === 3 || $platformId === 0) {
            return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        }

        if ($platformId === 1 && function_exists('iconv')) {
            $encoded = @iconv('UTF-8', 'macintosh//TRANSLIT//IGNORE', $value);

            if (is_string($encoded) && $encoded !== '') {
                return $encoded;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '-', $value) ?? $value;
    }

    /**
     * @param array<int, bool> $requiredGlyphSet
     * @param list<int> $glyphOffsets
     * @return array{0: string, 1: string}
     */
    private function buildSparseSubsetGlyphTables(
        string $glyf,
        array $glyphOffsets,
        array $requiredGlyphSet,
        int $subsetGlyphCount,
        int $indexToLocFormat,
    ): array {
        $subsetGlyf = '';
        $subsetGlyphOffsets = [];

        for ($glyphId = 0; $glyphId < $subsetGlyphCount; $glyphId++) {
            $subsetGlyphOffsets[] = strlen($subsetGlyf);

            if (!isset($requiredGlyphSet[$glyphId])) {
                continue;
            }

            $start = $glyphOffsets[$glyphId];
            $end = $glyphOffsets[$glyphId + 1];

            if ($end <= $start) {
                continue;
            }

            $subsetGlyf .= substr($glyf, $start, $end - $start);
        }

        $subsetGlyphOffsets[] = strlen($subsetGlyf);

        return [$subsetGlyf, $this->buildLocaTable($subsetGlyphOffsets, $indexToLocFormat)];
    }

    /**
     * @param list<int> $glyphOffsets
     * @param list<int> $requiredGlyphIds
     * @param array<int, int> $glyphIdMap
     * @return array{0: string, 1: string}
     */
    private function buildDenseSubsetGlyphTables(
        string $glyf,
        array $glyphOffsets,
        array $requiredGlyphIds,
        array $glyphIdMap,
        int $indexToLocFormat,
    ): array {
        $subsetGlyf = '';
        $subsetGlyphOffsets = [];

        foreach ($requiredGlyphIds as $oldGlyphId) {
            $subsetGlyphOffsets[] = strlen($subsetGlyf);
            $start = $glyphOffsets[$oldGlyphId];
            $end = $glyphOffsets[$oldGlyphId + 1];

            if ($end <= $start) {
                continue;
            }

            $glyphData = substr($glyf, $start, $end - $start);
            $subsetGlyf .= $this->rewriteCompositeGlyphData($glyphData, $glyphIdMap);
        }

        $subsetGlyphOffsets[] = strlen($subsetGlyf);

        return [$subsetGlyf, $this->buildLocaTable($subsetGlyphOffsets, $indexToLocFormat)];
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private function readTableDirectory(string $bytes): array
    {
        $numTables = $this->u16($bytes, 4);
        $tables = [];
        $offset = 12;

        for ($index = 0; $index < $numTables; $index++) {
            $tag = substr($bytes, $offset, 4);
            $tables[$tag] = [
                'offset' => $this->u32($bytes, $offset + 8),
                'length' => $this->u32($bytes, $offset + 12),
            ];
            $offset += 16;
        }

        return $tables;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     */
    private function table(string $bytes, array $tables, string $tag): string
    {
        if (!isset($tables[$tag])) {
            throw new PdfException(sprintf('TrueType font is missing required "%s" table for subsetting.', $tag));
        }

        return substr($bytes, $tables[$tag]['offset'], $tables[$tag]['length']);
    }

    /**
     * @return list<int>
     */
    private function parseLocaOffsets(string $loca, int $numGlyphs, int $indexToLocFormat): array
    {
        $offsets = [];

        for ($glyphId = 0; $glyphId <= $numGlyphs; $glyphId++) {
            if ($indexToLocFormat === 0) {
                $offsets[] = $this->u16($loca, $glyphId * 2) * 2;
                continue;
            }

            $offsets[] = $this->u32($loca, $glyphId * 4);
        }

        return $offsets;
    }

    /**
     * @param list<int> $requiredGlyphIds
     * @param list<int> $glyphOffsets
     * @return list<int>
     */
    private function expandCompositeDependencies(string $glyf, array $glyphOffsets, array $requiredGlyphIds): array
    {
        $queue = array_values(array_unique(array_merge([0], $requiredGlyphIds)));
        sort($queue);
        $seen = array_fill_keys($queue, true);

        for ($index = 0; $index < count($queue); $index++) {
            $glyphId = $queue[$index];

            foreach ($this->compositeComponents($glyf, $glyphOffsets, $glyphId) as $componentGlyphId) {
                if (isset($seen[$componentGlyphId])) {
                    continue;
                }

                $seen[$componentGlyphId] = true;
                $queue[] = $componentGlyphId;
            }
        }

        sort($queue);

        return $queue;
    }

    /**
     * @param list<int> $glyphOffsets
     * @return list<int>
     */
    private function compositeComponents(string $glyf, array $glyphOffsets, int $glyphId): array
    {
        $start = $glyphOffsets[$glyphId] ?? null;
        $end = $glyphOffsets[$glyphId + 1] ?? null;

        if ($start === null || $end === null || $start === $end) {
            return [];
        }

        $glyphData = substr($glyf, $start, $end - $start);

        if (strlen($glyphData) < 10) {
            return [];
        }

        $numberOfContours = $this->s16($glyphData, 0);

        if ($numberOfContours >= 0) {
            return [];
        }

        $components = [];
        $offset = 10;

        do {
            if ($offset + 4 > strlen($glyphData)) {
                break;
            }

            $flags = $this->u16($glyphData, $offset);
            $componentGlyphId = $this->u16($glyphData, $offset + 2);
            $components[] = $componentGlyphId;
            $offset += 4;

            $offset += ($flags & 0x0001) === 0x0001 ? 4 : 2;

            if (($flags & 0x0008) === 0x0008) {
                $offset += 2;
            } elseif (($flags & 0x0040) === 0x0040) {
                $offset += 4;
            } elseif (($flags & 0x0080) === 0x0080) {
                $offset += 8;
            }
        } while (($flags & 0x0020) === 0x0020);

        return $components;
    }

    /**
     * @param list<int> $glyphOffsets
     */
    private function buildLocaTable(array $glyphOffsets, int $indexToLocFormat): string
    {
        $bytes = '';

        foreach ($glyphOffsets as $offset) {
            if ($indexToLocFormat === 0) {
                $bytes .= pack('n', intdiv($offset, 2));
                continue;
            }

            $bytes .= pack('N', $offset);
        }

        return $bytes;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function buildHmtxTable(string $hmtx, int $subsetGlyphCount, int $numberOfHMetrics): array
    {
        $advanceWidths = [];
        $leftSideBearings = [];
        $offset = 0;
        $lastAdvanceWidth = 0;
        $leftSideBearingOffset = $numberOfHMetrics * 4;

        for ($glyphId = 0; $glyphId < $subsetGlyphCount; $glyphId++) {
            if ($glyphId < $numberOfHMetrics) {
                $lastAdvanceWidth = $this->u16($hmtx, $offset);
                $leftSideBearing = $this->s16($hmtx, $offset + 2);
                $offset += 4;
            } else {
                $leftSideBearing = $this->s16($hmtx, $leftSideBearingOffset + (($glyphId - $numberOfHMetrics) * 2));
            }

            $advanceWidths[] = $lastAdvanceWidth;
            $leftSideBearings[] = $leftSideBearing;
        }

        $subsetNumberOfHMetrics = $subsetGlyphCount;

        while (
            $subsetNumberOfHMetrics > 1
            && $advanceWidths[$subsetNumberOfHMetrics - 2] === $advanceWidths[$subsetGlyphCount - 1]
        ) {
            $subsetNumberOfHMetrics--;
        }

        $bytes = '';

        for ($glyphId = 0; $glyphId < $subsetNumberOfHMetrics; $glyphId++) {
            $bytes .= pack('n', $advanceWidths[$glyphId]) . pack('n', $leftSideBearings[$glyphId] & 0xFFFF);
        }

        for ($glyphId = $subsetNumberOfHMetrics; $glyphId < $subsetGlyphCount; $glyphId++) {
            $bytes .= pack('n', $leftSideBearings[$glyphId] & 0xFFFF);
        }

        return [$bytes, $subsetNumberOfHMetrics];
    }

    /**
     * @param list<int> $requiredGlyphIds
     * @return array{0: string, 1: int}
     */
    private function buildDenseHmtxTable(string $hmtx, array $requiredGlyphIds, int $numberOfHMetrics): array
    {
        $advanceWidths = [];
        $leftSideBearings = [];

        foreach ($requiredGlyphIds as $oldGlyphId) {
            [$advanceWidth, $leftSideBearing] = $this->glyphHorizontalMetrics($hmtx, $oldGlyphId, $numberOfHMetrics);
            $advanceWidths[] = $advanceWidth;
            $leftSideBearings[] = $leftSideBearing;
        }

        $subsetNumberOfHMetrics = count($requiredGlyphIds);

        while (
            $subsetNumberOfHMetrics > 1
            && $advanceWidths[$subsetNumberOfHMetrics - 2] === $advanceWidths[count($requiredGlyphIds) - 1]
        ) {
            $subsetNumberOfHMetrics--;
        }

        $bytes = '';

        for ($glyphId = 0; $glyphId < $subsetNumberOfHMetrics; $glyphId++) {
            $bytes .= pack('n', $advanceWidths[$glyphId]) . pack('n', $leftSideBearings[$glyphId] & 0xFFFF);
        }

        for ($glyphId = $subsetNumberOfHMetrics; $glyphId < count($requiredGlyphIds); $glyphId++) {
            $bytes .= pack('n', $leftSideBearings[$glyphId] & 0xFFFF);
        }

        return [$bytes, $subsetNumberOfHMetrics];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function glyphHorizontalMetrics(string $hmtx, int $glyphId, int $numberOfHMetrics): array
    {
        if ($glyphId < $numberOfHMetrics) {
            $offset = $glyphId * 4;

            return [
                $this->u16($hmtx, $offset),
                $this->s16($hmtx, $offset + 2),
            ];
        }

        return [
            $this->u16($hmtx, ($numberOfHMetrics - 1) * 4),
            $this->s16($hmtx, ($numberOfHMetrics * 4) + (($glyphId - $numberOfHMetrics) * 2)),
        ];
    }

    /**
     * @param array<int, int> $glyphIdMap
     */
    private function rewriteCompositeGlyphData(string $glyphData, array $glyphIdMap): string
    {
        if (strlen($glyphData) < 10 || $this->s16($glyphData, 0) >= 0) {
            return $glyphData;
        }

        $offset = 10;

        do {
            if ($offset + 4 > strlen($glyphData)) {
                break;
            }

            $flags = $this->u16($glyphData, $offset);
            $componentGlyphId = $this->u16($glyphData, $offset + 2);
            $mappedGlyphId = $glyphIdMap[$componentGlyphId] ?? $componentGlyphId;
            $glyphData = substr_replace($glyphData, pack('n', $mappedGlyphId), $offset + 2, 2);
            $offset += 4;
            $offset += ($flags & 0x0001) === 0x0001 ? 4 : 2;

            if (($flags & 0x0008) === 0x0008) {
                $offset += 2;
            } elseif (($flags & 0x0040) === 0x0040) {
                $offset += 4;
            } elseif (($flags & 0x0080) === 0x0080) {
                $offset += 8;
            }
        } while (($flags & 0x0020) === 0x0020);

        return $glyphData;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $tables
     * @param array<string, string> $tableData
     */
    private function buildSfnt(string $original, array $tables, array $tableData): string
    {
        $output = substr($original, 0, 12);
        $orderedTags = array_keys($tables);
        $nextOffset = 12 + (count($orderedTags) * 16);
        $records = [];
        $payload = '';

        foreach ($orderedTags as $tag) {
            $data = $tableData[$tag];
            $records[$tag] = [
                'offset' => $nextOffset,
                'length' => strlen($data),
                'checksum' => $this->tableChecksum($data),
            ];
            $padded = $data . str_repeat("\x00", (4 - (strlen($data) % 4)) % 4);
            $payload .= $padded;
            $nextOffset += strlen($padded);
        }

        foreach ($orderedTags as $tag) {
            $record = $records[$tag];
            $output .= $tag
                . pack('N', $record['checksum'])
                . pack('N', $record['offset'])
                . pack('N', $record['length']);
        }

        $output .= $payload;
        $headOffset = $records['head']['offset'];
        $output = substr_replace($output, "\x00\x00\x00\x00", $headOffset + 8, 4);
        $adjustment = (0xB1B0AFBA - $this->tableChecksum($output)) & 0xFFFFFFFF;

        return substr_replace($output, pack('N', $adjustment), $headOffset + 8, 4);
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
