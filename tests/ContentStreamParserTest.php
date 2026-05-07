<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Parser\ContentStreamParser;
use PdfToolkit\Parser\PdfName;
use PHPUnit\Framework\TestCase;

final class ContentStreamParserTest extends TestCase
{
    public function testParsesBasicTextAndGraphicsOperators(): void
    {
        $warnings = [];
        $operations = (new ContentStreamParser("q\n1 0 0 1 72 720 Tm\n(Hello) Tj\nQ"))->parse($warnings);

        $this->assertSame([], $warnings);
        $this->assertCount(4, $operations);
        $this->assertSame('q', $operations[0]->operator);
        $this->assertSame('Tm', $operations[1]->operator);
        $this->assertSame([1, 0, 0, 1, 72, 720], $operations[1]->operands);
        $this->assertSame('Tj', $operations[2]->operator);
        $this->assertSame(['Hello'], $operations[2]->operands);
        $this->assertSame('Q', $operations[3]->operator);
    }

    public function testParsesNamesArraysAndDictionariesAsOperands(): void
    {
        $warnings = [];
        $operations = (new ContentStreamParser("/Span << /Lang /en >> BDC\n/F1 12 Tf"))->parse($warnings);

        $this->assertCount(2, $operations);
        $this->assertSame('BDC', $operations[0]->operator);
        $this->assertEquals([new PdfName('Span'), ['Lang' => new PdfName('en')]], $operations[0]->operands);
        $this->assertSame('Tf', $operations[1]->operator);
        $this->assertEquals([new PdfName('F1'), 12], $operations[1]->operands);
    }
}
