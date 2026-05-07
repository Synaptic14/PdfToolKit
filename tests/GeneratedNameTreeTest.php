<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedNameTreeTest extends TestCase
{
    public function testGeneratedNamedDestinationsAreWrittenToNameTree(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->namedDestination('intro', 1, left: 10, top: 700)
            ->build()
            ->save();

        $this->assertStringContainsString('/Names', $bytes);
        $this->assertStringContainsString('/Dests', $bytes);
        $this->assertStringContainsString('(intro)', $bytes);
        $this->assertStringContainsString('/XYZ 10 142 null', $bytes);
    }

    public function testGeneratedLinksCanTargetNamedDestinations(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->linkAnnotation(LinkAnnotation::toNamedDestination('intro', 72, 700, 120, 20))
            ->endPage()
            ->namedDestination('intro', 1)
            ->build()
            ->save();

        $this->assertStringContainsString('/Dest (intro)', $bytes);
        $this->assertStringNotContainsString('/S /URI', $bytes);
    }

    public function testGeneratedNamedDestinationRejectsMissingPageOnSave(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addNamedDestination('missing', 2);

        $this->expectException(\LogicException::class);

        $document->save();
    }

    public function testAppendDocumentRemapsNamedDestinations(): void
    {
        $left = new Document();
        $left->addPage(new Page(200, 400));

        $right = new Document();
        $right->addPage(new Page(200, 400));
        $right->addNamedDestination('right', 1);

        $left->appendDocument($right);

        $this->assertSame('right', $left->namedDestinations()[0]->name);
        $this->assertSame(2, $left->namedDestinations()[0]->pageNumber);
    }

    public function testExtractPagesRemapsNamedDestinations(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addPage(new Page(200, 400));
        $document->addNamedDestination('second', 2);

        $extracted = $document->extractPages(2);

        $this->assertSame('second', $extracted->namedDestinations()[0]->name);
        $this->assertSame(1, $extracted->namedDestinations()[0]->pageNumber);
    }
}
