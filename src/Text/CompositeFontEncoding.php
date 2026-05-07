<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Core\PdfException;

final readonly class CompositeFontEncoding
{
    /**
     * @param array<string, int> $characterToCid
     */
    public array $characterToCid;

    /**
     * @param array<string, int> $characterToCid
     */
    public function __construct(array $characterToCid)
    {
        $normalized = [];

        foreach ($characterToCid as $character => $cid) {
            $key = str_starts_with($character, 'g:') || str_starts_with($character, 'l:')
                ? $character
                : CharacterMap::key($character);
            $normalized[$key] = $cid;
        }

        $this->characterToCid = $normalized;
    }

    public function encode(string $text): EncodedText
    {
        $bytes = '';

        foreach (mb_str_split($text) as $character) {
            $bytes .= $this->encodeCharacter($character)->bytes;
        }

        return new EncodedText($bytes);
    }

    /**
     * @param list<string> $keys
     */
    public function encodeKeys(array $keys): EncodedText
    {
        $bytes = '';

        foreach ($keys as $key) {
            $bytes .= $this->encodeKey($key)->bytes;
        }

        return new EncodedText($bytes);
    }

    public function encodeCharacter(string $character): EncodedText
    {
        return $this->encodeKey(CharacterMap::key($character));
    }

    public function encodeKey(string $key): EncodedText
    {
        $cid = $this->characterToCid[$key] ?? null;

        if ($cid === null) {
            throw new PdfException(sprintf('No composite-font CID mapping exists for key "%s".', $key));
        }

        return new EncodedText(
            chr(($cid >> 8) & 0xFF) . chr($cid & 0xFF),
        );
    }
}
