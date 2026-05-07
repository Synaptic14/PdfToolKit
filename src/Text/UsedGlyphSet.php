<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final class UsedGlyphSet
{
    /**
     * @var array<string, true>
     */
    private array $characters = [];

    public function addText(string $text): void
    {
        foreach (mb_str_split($text) as $character) {
            $this->addKey(CharacterMap::key($character));
        }
    }

    public function addKey(string $key): void
    {
        $this->characters[$key] = true;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->characters);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function characters(): array
    {
        $characters = array_map(
            static fn (string $key): string => CharacterMap::character($key),
            array_keys($this->characters)
        );
        sort($characters);

        return $characters;
    }
}
