<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Navigation\PageLabelRange;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedPageLabelsTest extends TestCase
{
    public function testGeneratedPageLabelsAreWrittenToCatalog(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->addPage()
            ->endPage()
            ->pageLabel(1, PageLabelRange::ROMAN_LOWER, prefix: 'intro-')
            ->pageLabel(2, PageLabelRange::DECIMAL, startNumber: 1)
            ->build()
            ->save();

        $this->assertStringContainsString('/PageLabels', $bytes);
        $this->assertStringContainsString('/Nums [0 << /S /r /P (intro-) >> 1 << /S /D >>]', $bytes);
    }

    public function testGeneratedPageLabelRejectsInvalidStyle(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));

        $this->expectException(PdfException::class);

        $document->addPageLabel(1, 'invalid');
    }

    public function testGeneratedPageLabelRejectsMissingPageOnSave(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addPageLabel(2);

        $this->expectException(\LogicException::class);

        $document->save();
    }

    public function testAppendDocumentRemapsPageLabels(): void
    {
        $left = new Document();
        $left->addPage(new Page(200, 400));

        $right = new Document();
        $right->addPage(new Page(200, 400));
        $right->addPageLabel(1, PageLabelRange::DECIMAL, prefix: 'B-');

        $left->appendDocument($right);

        $this->assertSame(2, $left->pageLabelRanges()[0]->startPage);
        $this->assertSame('B-', $left->pageLabelRanges()[0]->prefix);
    }

    public function testExtractPagesRemapsPageLabels(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addPage(new Page(200, 400));
        $document->addPageLabel(2, PageLabelRange::DECIMAL, prefix: 'B-');

        $extracted = $document->extractPages(2);

        $this->assertSame(1, $extracted->pageLabelRanges()[0]->startPage);
        $this->assertSame('B-', $extracted->pageLabelRanges()[0]->prefix);
    }
}
