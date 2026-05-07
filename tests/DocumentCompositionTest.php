<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Text\TextRun;
use PHPUnit\Framework\TestCase;

final class DocumentCompositionTest extends TestCase
{
    public function testExtractPagesCreatesDocumentWithSelectedPages(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 300));
        $document->addPage(new Page(400, 500));
        $document->addPage(new Page(600, 700));

        $extracted = $document->extractPages(2, 3);

        $this->assertCount(2, $extracted->pages());
        $this->assertSame(400.0, $extracted->page(0)->width());
        $this->assertSame(700.0, $extracted->page(1)->height());
    }

    public function testSplitCreatesOneDocumentPerPage(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 300));
        $document->addPage(new Page(400, 500));

        $split = $document->split();

        $this->assertCount(2, $split);
        $this->assertCount(1, $split[0]->pages());
        $this->assertSame(200.0, $split[0]->page(0)->width());
        $this->assertSame(400.0, $split[1]->page(0)->width());
    }

    public function testAppendDocumentCopiesPages(): void
    {
        $left = new Document();
        $left->addPage(new Page(200, 300));

        $rightPage = new Page(400, 500);
        $rightPage->addText(new TextRun('Merged page', 72, 72));
        $right = new Document();
        $right->addPage($rightPage);

        $left->appendDocument($right);
        $rightPage->addText(new TextRun('After merge', 72, 90));

        $this->assertCount(2, $left->pages());
        $this->assertSame(400.0, $left->page(1)->width());
        $this->assertCount(1, $left->page(1)->texts());
    }

    public function testAppendDocumentRemapsOutlines(): void
    {
        $left = new Document();
        $left->addPage(new Page(200, 300));
        $left->addOutline('Left', 1);

        $right = new Document();
        $right->addPage(new Page(400, 500));
        $right->addPage(new Page(600, 700));
        $right->addOutline('Right second page', 2, level: 1);

        $left->appendDocument($right);

        $this->assertSame('Left', $left->outlineItems()[0]->title);
        $this->assertSame(1, $left->outlineItems()[0]->pageNumber);
        $this->assertSame('Right second page', $left->outlineItems()[1]->title);
        $this->assertSame(3, $left->outlineItems()[1]->pageNumber);
        $this->assertSame(1, $left->outlineItems()[1]->level);
    }

    public function testExtractPagesRemapsOutlinesInRange(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 300));
        $document->addPage(new Page(400, 500));
        $document->addPage(new Page(600, 700));
        $document->addOutline('First', 1);
        $document->addOutline('Second', 2);
        $document->addOutline('Third', 3, level: 1);

        $extracted = $document->extractPages(2, 3);

        $this->assertCount(2, $extracted->outlineItems());
        $this->assertSame('Second', $extracted->outlineItems()[0]->title);
        $this->assertSame(1, $extracted->outlineItems()[0]->pageNumber);
        $this->assertSame('Third', $extracted->outlineItems()[1]->title);
        $this->assertSame(2, $extracted->outlineItems()[1]->pageNumber);
        $this->assertSame(1, $extracted->outlineItems()[1]->level);
    }

    public function testExtractPagesNormalizesOrphanedOutlineLevels(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 300));
        $document->addPage(new Page(400, 500));
        $document->addOutline('Parent outside range', 1);
        $document->addOutline('Child in range', 2, level: 1);

        $extracted = $document->extractPages(2);

        $this->assertCount(1, $extracted->outlineItems());
        $this->assertSame('Child in range', $extracted->outlineItems()[0]->title);
        $this->assertSame(0, $extracted->outlineItems()[0]->level);
    }

    public function testExtractPagesRejectsInvalidRanges(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 300));

        $this->expectException(PdfException::class);

        $document->extractPages(0);
    }
}
