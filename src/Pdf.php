<?php

declare(strict_types=1);

namespace PdfToolkit;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\DocumentBuilder;
use PdfToolkit\Import\ImportedDocument;
use PdfToolkit\Import\Importer;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\TextMeasurer;

final class Pdf
{
    private function __construct()
    {
    }

    public static function new(): DocumentBuilder
    {
        return new DocumentBuilder();
    }

    public static function load(string $path, ?string $password = null): ImportedDocument
    {
        return (new Importer())->load($path, $password);
    }

    public static function loadString(string $contents, ?string $password = null): ImportedDocument
    {
        return (new Importer())->loadString($contents, $password);
    }

    public static function document(): Document
    {
        return new Document();
    }

    public static function font(string $family = 'Helvetica', string $style = 'normal', bool $embed = true): FontReference
    {
        return FontReference::builtin($family, $style, $embed);
    }

    public static function trueTypeFont(string $path, ?string $family = null, string $style = 'normal', int $faceIndex = 0): FontReference
    {
        return FontReference::trueType($path, $family, $style, $faceIndex);
    }

    public static function measureText(string $text, float $fontSize = 12.0, ?FontReference $font = null): float
    {
        return (new TextMeasurer())->width($text, $fontSize, $font);
    }
}
