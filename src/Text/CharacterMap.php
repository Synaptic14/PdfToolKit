<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final class CharacterMap
{
    private function __construct()
    {
    }

    public static function key(string $character): string
    {
        return 'g:' . $character;
    }

    public static function sourceKey(string $sourceText, string $character): string
    {
        return 'l:' . bin2hex($sourceText) . ':' . bin2hex($character);
    }

    public static function ligatureKey(string $sourceText, string $character): string
    {
        return self::sourceKey($sourceText, $character);
    }

    public static function character(string $key): string
    {
        if (str_starts_with($key, 'l:')) {
            $parts = explode(':', $key, 3);

            return isset($parts[2]) ? hex2bin($parts[2]) ?: '' : '';
        }

        return str_starts_with($key, 'g:') ? substr($key, 2) : $key;
    }

    public static function sourceText(string $key): string
    {
        if (str_starts_with($key, 'l:')) {
            $parts = explode(':', $key, 3);

            return isset($parts[1]) ? hex2bin($parts[1]) ?: '' : '';
        }

        return self::character($key);
    }
}
