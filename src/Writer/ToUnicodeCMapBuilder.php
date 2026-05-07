<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

use PdfToolkit\Text\CharacterMap;

final class ToUnicodeCMapBuilder
{
    /**
     * @param list<string> $characters
     */
    public function build(array $characters): string
    {
        $entries = [];

        foreach ($characters as $character) {
            $code = ord($character);
            $unicode = $this->utf16Hex($character);
            $entries[] = sprintf('<%02X> <%s>', $code, $unicode);
        }

        $body = [
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /PdfToolkit-ToUnicode def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<00> <FF>',
            'endcodespacerange',
        ];

        foreach (array_chunk($entries, 100) as $chunk) {
            $body[] = count($chunk) . ' beginbfchar';

            foreach ($chunk as $entry) {
                $body[] = $entry;
            }

            $body[] = 'endbfchar';
        }

        $body[] = 'endcmap';
        $body[] = 'CMapName currentdict /CMap defineresource pop';
        $body[] = 'end';
        $body[] = 'end';

        return implode("\n", $body);
    }

    /**
     * @param array<string, int> $characterToCid
     */
    public function buildComposite(array $characterToCid): string
    {
        $entries = [];

        foreach ($characterToCid as $characterKey => $cid) {
            $unicode = $this->utf16Hex(CharacterMap::sourceText($characterKey));
            $entries[] = sprintf('<%04X> <%s>', $cid, $unicode);
        }

        return $this->buildCMap($entries, '<0000> <FFFF>');
    }

    /**
     * @param list<string> $entries
     */
    private function buildCMap(array $entries, string $codeSpaceRange): string
    {
        $body = [
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /PdfToolkit-ToUnicode def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            $codeSpaceRange,
            'endcodespacerange',
        ];

        foreach (array_chunk($entries, 100) as $chunk) {
            $body[] = count($chunk) . ' beginbfchar';

            foreach ($chunk as $entry) {
                $body[] = $entry;
            }

            $body[] = 'endbfchar';
        }

        $body[] = 'endcmap';
        $body[] = 'CMapName currentdict /CMap defineresource pop';
        $body[] = 'end';
        $body[] = 'end';

        return implode("\n", $body);
    }

    private function utf16Hex(string $character): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $character);

        if ($encoded === false) {
            return 'FFFD';
        }

        return strtoupper(bin2hex($encoded));
    }
}
