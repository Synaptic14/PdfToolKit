<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

final readonly class FontReference
{
    public function __construct(
        public string $family = 'Helvetica',
        public string $style = 'normal',
        public bool $embed = true,
        public ?string $sourcePath = null,
        public int $faceIndex = 0,
    ) {
    }

    public static function builtin(string $family = 'Helvetica', string $style = 'normal', bool $embed = true): self
    {
        return new self($family, $style, $embed);
    }

    public static function trueType(string $path, ?string $family = null, string $style = 'normal', int $faceIndex = 0): self
    {
        $resolvedFamily = $family;

        if ($resolvedFamily === null || $resolvedFamily === '') {
            $resolvedFamily = pathinfo($path, PATHINFO_FILENAME);
        }

        return new self($resolvedFamily !== '' ? $resolvedFamily : 'CustomTrueType', $style, true, $path, $faceIndex);
    }
}
