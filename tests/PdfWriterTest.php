<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Text\CharacterMap;
use PdfToolkit\Writer\PdfWriter;
use PHPUnit\Framework\TestCase;

final class PdfWriterTest extends TestCase
{
    public function testRequiresCompositeTrueTypeFontWhenShapedTokenPreservesDifferentSourceText(): void
    {
        $writer = new PdfWriter();
        $method = new \ReflectionMethod($writer, 'requiresCompositeTrueTypeFont');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($writer, [CharacterMap::sourceKey('ffi', 'f')]));
        $this->assertFalse($method->invoke($writer, [CharacterMap::key('A')]));
    }
}
