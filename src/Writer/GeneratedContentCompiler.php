<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

use PdfToolkit\Core\ImportedContentOperation;
use PdfToolkit\Core\Page;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Parser\ContentStreamSerializer;
use PdfToolkit\Parser\PdfName;
use PdfToolkit\Text\CompositeFontEncoding;
use PdfToolkit\Text\EncodedText;
use PdfToolkit\Text\FontRegistry;
use PdfToolkit\Text\ParsedTrueTypeFont;
use PdfToolkit\Text\ResolvedFont;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Text\TrueTypeFontParser;
use PdfToolkit\Text\TrueTypeTextShaper;
use PdfToolkit\Text\UsedGlyphSet;

final class GeneratedContentCompiler
{
    private float $pageHeight = 0.0;
    /** @var array<string, ParsedTrueTypeFont> */
    private array $parsedTrueTypeFonts = [];
    /** @var array<string, string> */
    private array $imageKeys = [];
    private ?ContentStreamSerializer $contentStreamSerializer = null;

    public function __construct(
        private ?FontRegistry $fontRegistry = null,
        private ?TrueTypeFontParser $trueTypeFontParser = null,
        private ?TrueTypeTextShaper $trueTypeTextShaper = null,
    ) {
    }

    /**
     * @return list<ImportedContentOperation>
     */
    public function compileOperations(Page $page, array $fontResourceNames = [], array $imageResourceNames = [], array $fontTextEncodings = []): array
    {
        $this->pageHeight = $page->height();
        $operations = [];

        foreach ($page->texts() as $text) {
            foreach ($this->compileText($text, $fontResourceNames, $fontTextEncodings) as $operation) {
                $operations[] = $operation;
            }
        }

        foreach ($page->lines() as $line) {
            foreach ($this->compileLine($line) as $operation) {
                $operations[] = $operation;
            }
        }

        foreach ($page->rectangles() as $rectangle) {
            foreach ($this->compileRectangle($rectangle) as $operation) {
                $operations[] = $operation;
            }
        }

        foreach ($page->images() as $image) {
            foreach ($this->compileImage($image, $imageResourceNames) as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    public function compile(Page $page, array $fontResourceNames = [], array $imageResourceNames = [], array $fontTextEncodings = []): string
    {
        return $this->contentStreamSerializer()->serialize(
            $this->compileOperations($page, $fontResourceNames, $imageResourceNames, $fontTextEncodings)
        );
    }

    /**
     * @return array{
     *     fonts: array<string, ResolvedFont>,
     *     glyphs: array<string, UsedGlyphSet>,
     *     images: array<string, ImagePlacement>
     * }
     */
    public function analyzePage(Page $page): array
    {
        $fonts = [];
        $glyphs = [];
        $images = [];

        foreach ($page->texts() as $text) {
            $resolved = $this->fontRegistry()->resolve($text->font);
            $fontKey = $resolved->key();
            $fonts[$fontKey] = $resolved;
            $glyphs[$fontKey] ??= new UsedGlyphSet();

            foreach ($this->shapeTextTokens($text->text, $resolved) as $token) {
                $glyphs[$fontKey]->addKey($token['key']);
            }
        }

        foreach ($page->images() as $image) {
            $images[$this->imageKey($image)] = $image;
        }

        return [
            'fonts' => $fonts,
            'glyphs' => $glyphs,
            'images' => $images,
        ];
    }

    /**
     * @return array<string, ResolvedFont>
     */
    public function collectFonts(Page $page): array
    {
        return $this->analyzePage($page)['fonts'];
    }

    /**
     * @return array<string, UsedGlyphSet>
     */
    public function collectUsedGlyphs(Page $page): array
    {
        return $this->analyzePage($page)['glyphs'];
    }

    /**
     * @return array<string, ImagePlacement>
     */
    public function collectImages(Page $page): array
    {
        return $this->analyzePage($page)['images'];
    }

    /**
     * @return list<ImportedContentOperation>
     */
    private function compileText(TextRun $text, array $fontResourceNames, array $fontTextEncodings): array
    {
        $color = $text->color ?? Color::black();
        $resolvedFont = $this->fontRegistry()->resolve($text->font);
        $resourceName = $fontResourceNames[$resolvedFont->key()] ?? 'PT_F1';
        $textEncoding = $fontTextEncodings[$resolvedFont->key()] ?? null;
        $pdfY = max(0.0, $this->pageHeight - $text->y - $text->fontSize);
        $shapedTokens = $this->shapeTextTokens($text->text, $resolvedFont);
        $textShowingOperation = $this->compileTextShowingOperation($resolvedFont, $textEncoding, $shapedTokens);

        return [
            new ImportedContentOperation('BT'),
            new ImportedContentOperation('Tf', [new PdfName($resourceName), $text->fontSize]),
            new ImportedContentOperation($color->fillOperator(), $color->components()),
            new ImportedContentOperation('Tm', [1, 0, 0, 1, $text->x, $pdfY]),
            $textShowingOperation,
            new ImportedContentOperation('ET'),
        ];
    }

    private function compileTextShowingOperation(
        ResolvedFont $resolvedFont,
        ?CompositeFontEncoding $textEncoding,
        array $shapedTokens,
    ): ImportedContentOperation {
        $kerningOperands = $this->kerningTextOperands($resolvedFont, $textEncoding, $shapedTokens);

        if ($kerningOperands !== null) {
            return new ImportedContentOperation('TJ', [$kerningOperands]);
        }

        $textOperand = $textEncoding instanceof CompositeFontEncoding
            ? $textEncoding->encodeKeys(array_column($shapedTokens, 'key'))
            : implode('', array_column($shapedTokens, 'display'));

        return new ImportedContentOperation('Tj', [$textOperand]);
    }

    /**
     * @return list<string|int|float|EncodedText>|null
     */
    private function kerningTextOperands(
        ResolvedFont $resolvedFont,
        ?CompositeFontEncoding $textEncoding,
        array $shapedTokens,
    ): ?array {
        if ($resolvedFont->sourcePath === null) {
            return null;
        }

        $parsedFont = $this->parsedTrueTypeFont($resolvedFont);
        $characters = array_column($shapedTokens, 'display');

        if (count($characters) < 2) {
            return null;
        }

        $operands = [];
        $hasKerning = false;

        foreach ($characters as $index => $character) {
            $operands[] = $textEncoding instanceof CompositeFontEncoding
                ? $textEncoding->encodeKey($shapedTokens[$index]['key'])
                : $character;

            if ($index === array_key_last($characters)) {
                continue;
            }

            $leftGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord($character));
            $rightGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord($characters[$index + 1]));

            if ($leftGlyphId === null || $rightGlyphId === null) {
                continue;
            }

            $kerning = $parsedFont->kerningForGlyphPair($leftGlyphId, $rightGlyphId);

            if ($kerning === 0) {
                continue;
            }

            $adjustment = (int) round(((-1 * $kerning) / max(1, $parsedFont->unitsPerEm)) * 1000);

            if ($adjustment === 0) {
                continue;
            }

            $operands[] = $adjustment;
            $hasKerning = true;
        }

        return $hasKerning ? $operands : null;
    }

    /**
     * @return list<ImportedContentOperation>
     */
    private function compileLine(Line $line): array
    {
        $color = $line->strokeColor ?? Color::black();
        $y1 = $this->pageHeight - $line->y1;
        $y2 = $this->pageHeight - $line->y2;

        return [
            new ImportedContentOperation('w', [$line->width]),
            new ImportedContentOperation($color->strokeOperator(), $color->components()),
            new ImportedContentOperation('m', [$line->x1, $y1]),
            new ImportedContentOperation('l', [$line->x2, $y2]),
            new ImportedContentOperation('S'),
        ];
    }

    /**
     * @return list<ImportedContentOperation>
     */
    private function compileRectangle(Rectangle $rectangle): array
    {
        $operations = [];
        $pdfY = $this->pageHeight - $rectangle->y - $rectangle->height;

        if ($rectangle->fillColor !== null) {
            $operations[] = new ImportedContentOperation(
                $rectangle->fillColor->fillOperator(),
                $rectangle->fillColor->components(),
            );
        }

        if ($rectangle->strokeColor !== null) {
            $operations[] = new ImportedContentOperation(
                $rectangle->strokeColor->strokeOperator(),
                $rectangle->strokeColor->components(),
            );
        }

        $operations[] = new ImportedContentOperation('w', [$rectangle->lineWidth]);
        $operations[] = new ImportedContentOperation('re', [
            $rectangle->x,
            $pdfY,
            $rectangle->width,
            $rectangle->height,
        ]);
        $operations[] = new ImportedContentOperation(
            $rectangle->fillColor !== null && $rectangle->strokeColor !== null
                ? 'B'
                : ($rectangle->fillColor !== null ? 'f' : 'S')
        );

        return $operations;
    }

    /**
     * @return list<ImportedContentOperation>
     */
    private function compileImage(ImagePlacement $image, array $imageResourceNames): array
    {
        $resourceName = $imageResourceNames[$this->imageKey($image)] ?? 'PT_Im1';
        $pdfY = $this->pageHeight - $image->y - $image->height;

        return [
            new ImportedContentOperation('q'),
            new ImportedContentOperation('cm', [$image->width, 0, 0, $image->height, $image->x, $pdfY]),
            new ImportedContentOperation('Do', [new PdfName($resourceName)]),
            new ImportedContentOperation('Q'),
        ];
    }

    private function fontRegistry(): FontRegistry
    {
        return $this->fontRegistry ??= new FontRegistry();
    }

    private function imageKey(ImagePlacement $image): string
    {
        if ($image->hasInlineData()) {
            return sha1(($image->format ?? 'unknown') . ':' . $image->path . ':' . $image->data);
        }

        return $this->imageKeys[$image->path] ??= (static function (string $path): string {
            $resolvedPath = realpath($path);

            return sha1($resolvedPath !== false ? $resolvedPath : $path);
        })($image->path);
    }

    private function parsedTrueTypeFont(ResolvedFont $font): ParsedTrueTypeFont
    {
        return $this->parsedTrueTypeFonts[$font->key()]
            ??= $this->trueTypeFontParser()->parse((string) $font->sourcePath, $font->faceIndex);
    }

    /**
     * @return list<array{key: string, display: string}>
     */
    private function shapeTextTokens(string $text, ResolvedFont $font): array
    {
        if ($font->sourcePath === null || $text === '') {
            return array_map(
                static fn (string $character): array => [
                    'key' => \PdfToolkit\Text\CharacterMap::key($character),
                    'display' => $character,
                ],
                $text === '' ? [] : mb_str_split($text),
            );
        }

        return $this->trueTypeTextShaper()->shapeTokens($text, $this->parsedTrueTypeFont($font));
    }

    private function trueTypeFontParser(): TrueTypeFontParser
    {
        return $this->trueTypeFontParser ??= new TrueTypeFontParser();
    }

    private function trueTypeTextShaper(): TrueTypeTextShaper
    {
        return $this->trueTypeTextShaper ??= new TrueTypeTextShaper();
    }

    private function contentStreamSerializer(): ContentStreamSerializer
    {
        return $this->contentStreamSerializer ??= new ContentStreamSerializer();
    }
}
