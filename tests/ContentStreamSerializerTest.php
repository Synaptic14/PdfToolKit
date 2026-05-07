<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\ImportedContentOperation;
use PdfToolkit\Parser\ContentStreamSerializer;
use PdfToolkit\Parser\PdfName;
use PdfToolkit\Text\CharacterMap;
use PdfToolkit\Text\CompositeFontEncoding;
use PdfToolkit\Text\EncodedText;
use PHPUnit\Framework\TestCase;

final class ContentStreamSerializerTest extends TestCase
{
    public function testSerializesBasicOperationsBackToPdfSyntax(): void
    {
        $serialized = (new ContentStreamSerializer())->serialize([
            new ImportedContentOperation('BT'),
            new ImportedContentOperation('Tf', [new PdfName('F1'), 12]),
            new ImportedContentOperation('Tj', ['Hello (PDF)']),
            new ImportedContentOperation('ET'),
        ]);

        $this->assertSame("BT\n/F1 12 Tf\n(Hello \\(PDF\\)) Tj\nET", $serialized);
    }

    public function testSerializesComplexOperands(): void
    {
        $serialized = (new ContentStreamSerializer())->serialize([
            new ImportedContentOperation('BDC', [new PdfName('Span'), ['Lang' => new PdfName('en')]]),
            new ImportedContentOperation('TJ', [['Hello', -120, 'World']]),
        ]);

        $this->assertSame("/Span << /Lang /en >> BDC\n[(Hello) -120 (World)] TJ", $serialized);
    }

    public function testSerializesNonAsciiStringsAsUtf16Hex(): void
    {
        $serialized = (new ContentStreamSerializer())->serialize([
            new ImportedContentOperation('Tj', ['Café']),
        ]);

        $this->assertSame('<FEFF00430061006600E9> Tj', $serialized);
    }

    public function testSerializesPreEncodedTextBytes(): void
    {
        $serialized = (new ContentStreamSerializer())->serialize([
            new ImportedContentOperation('Tj', [new EncodedText("\x00\x01\x00\x02")]),
        ]);

        $this->assertSame('<00010002> Tj', $serialized);
    }

    public function testCompositeFontEncodingProducesSequentialCidBytes(): void
    {
        $encoding = new CompositeFontEncoding([
            'A' => 1,
            '😀' => 2,
        ]);

        $encoded = $encoding->encode('A😀');

        $this->assertSame("\x00\x01\x00\x02", $encoded->bytes);
        $this->assertTrue($encoded->hex);
    }

    public function testCompositeFontEncodingCanEncodeSingleCharacter(): void
    {
        $encoding = new CompositeFontEncoding([
            'A' => 1,
            'é' => 2,
        ]);

        $encoded = $encoding->encodeCharacter('é');

        $this->assertSame("\x00\x02", $encoded->bytes);
        $this->assertTrue($encoded->hex);
    }

    public function testCompositeFontEncodingPreservesSourceAwareKeys(): void
    {
        $encoding = new CompositeFontEncoding([
            CharacterMap::sourceKey('A', "\u{00AA}") => 7,
        ]);

        $encoded = $encoding->encodeKey(CharacterMap::sourceKey('A', "\u{00AA}"));

        $this->assertSame("\x00\x07", $encoded->bytes);
        $this->assertTrue($encoded->hex);
    }
}
