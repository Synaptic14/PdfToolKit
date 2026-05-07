<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Core\PdfException;

final class FontRegistry
{
    /** @var array<string, ResolvedFont> */
    private array $resolvedFonts = [];

    public function __construct(
        private readonly TrueTypeFontParser $trueTypeFontParser = new TrueTypeFontParser(),
    ) {
    }

    public function resolve(?FontReference $reference = null): ResolvedFont
    {
        $reference ??= new FontReference();

        if ($reference->sourcePath !== null) {
            return $this->resolveCustomTrueType($reference);
        }

        $family = strtolower($reference->family);
        $style = $this->normalizeStyle($reference->style);
        $cacheKey = strtolower($family . '#' . $style);

        if (isset($this->resolvedFonts[$cacheKey])) {
            return $this->resolvedFonts[$cacheKey];
        }

        $baseFont = match ($family) {
            'helvetica', 'arial', 'sans-serif', 'sans' => $this->resolveHelvetica($style),
            'times', 'times-roman', 'times new roman', 'serif' => $this->resolveTimes($style),
            'courier', 'monospace', 'mono' => $this->resolveCourier($style),
            'symbol' => 'Symbol',
            'zapfdingbats', 'dingbats' => 'ZapfDingbats',
            default => throw new PdfException(sprintf('Unsupported built-in font family: %s', $reference->family)),
        };

        return $this->resolvedFonts[$cacheKey] = new ResolvedFont(
            family: $reference->family,
            style: $style,
            baseFont: $baseFont,
            embed: false,
        );
    }

    private function resolveCustomTrueType(FontReference $reference): ResolvedFont
    {
        $resolvedPath = realpath($reference->sourcePath);

        if ($resolvedPath === false || !is_file($resolvedPath)) {
            throw new PdfException(sprintf('Custom font file not found: %s', (string) $reference->sourcePath));
        }

        $style = $this->normalizeStyle($reference->style);
        $cacheKey = strtolower($resolvedPath . '#' . $reference->faceIndex . '#' . $reference->family . '#' . $style);

        if (isset($this->resolvedFonts[$cacheKey])) {
            return $this->resolvedFonts[$cacheKey];
        }

        $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['ttf', 'ttc'], true)) {
            throw new PdfException(sprintf('Only TrueType (.ttf) and TrueType Collection (.ttc) custom fonts are supported right now: %s', $resolvedPath));
        }

        $parsedFont = $this->trueTypeFontParser->parse($resolvedPath, $reference->faceIndex);

        if (!$parsedFont->allowsEmbedding()) {
            throw new PdfException(sprintf(
                'The font does not permit embedding (%s): %s',
                $parsedFont->embeddingRightsDescription(),
                $resolvedPath
            ));
        }

        $baseName = pathinfo($resolvedPath, PATHINFO_FILENAME);
        $baseName .= $reference->faceIndex > 0 ? '-face' . $reference->faceIndex : '';
        $postScriptName = $parsedFont->postScriptName
            ?? (preg_replace('/[^A-Za-z0-9_-]+/', '-', $baseName) ?: 'PdfToolkitCustomFont');

        return $this->resolvedFonts[$cacheKey] = new ResolvedFont(
            family: $reference->family,
            style: $style,
            baseFont: $postScriptName,
            embed: true,
            sourcePath: $resolvedPath,
            subtype: 'TrueType',
            faceIndex: $reference->faceIndex,
        );
    }

    private function normalizeStyle(string $style): string
    {
        $normalized = strtolower(str_replace(['_', ' '], ['-', ''], $style));

        return match ($normalized) {
            '', 'normal', 'regular', 'roman' => 'normal',
            'bold' => 'bold',
            'italic', 'oblique' => 'italic',
            'bolditalic', 'italicbold', 'bold-oblique', 'obliquebold' => 'bold-italic',
            default => throw new PdfException(sprintf('Unsupported font style: %s', $style)),
        };
    }

    private function resolveHelvetica(string $style): string
    {
        return match ($style) {
            'normal' => 'Helvetica',
            'bold' => 'Helvetica-Bold',
            'italic' => 'Helvetica-Oblique',
            'bold-italic' => 'Helvetica-BoldOblique',
        };
    }

    private function resolveTimes(string $style): string
    {
        return match ($style) {
            'normal' => 'Times-Roman',
            'bold' => 'Times-Bold',
            'italic' => 'Times-Italic',
            'bold-italic' => 'Times-BoldItalic',
        };
    }

    private function resolveCourier(string $style): string
    {
        return match ($style) {
            'normal' => 'Courier',
            'bold' => 'Courier-Bold',
            'italic' => 'Courier-Oblique',
            'bold-italic' => 'Courier-BoldOblique',
        };
    }
}
