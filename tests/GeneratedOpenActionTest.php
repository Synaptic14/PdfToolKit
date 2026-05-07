<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Navigation\OpenAction;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedOpenActionTest extends TestCase
{
    public function testGeneratedOpenActionCanTargetPageDestination(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->openAction(OpenAction::toPage(1, left: 10, top: 700))
            ->build()
            ->save();

        $this->assertStringContainsString('/OpenAction', $bytes);
        $this->assertStringContainsString('/XYZ 10 142 null', $bytes);
    }

    public function testGeneratedOpenActionCanTargetNamedDestination(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->namedDestination('intro', 1)
            ->openAction(OpenAction::toNamedDestination('intro'))
            ->build()
            ->save();

        $this->assertStringContainsString('/OpenAction (intro)', $bytes);
    }

    public function testGeneratedOpenActionRejectsInvalidPageNumber(): void
    {
        $document = new Document();

        $this->expectException(PdfException::class);

        $document->setOpenAction(OpenAction::toPage(0));
    }

    public function testGeneratedOpenActionRejectsMissingPageOnSave(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->setOpenAction(OpenAction::toPage(2));

        $this->expectException(\LogicException::class);

        $document->save();
    }

    public function testAppendDocumentRemapsPageOpenAction(): void
    {
        $left = new Document();
        $left->addPage(new Page(200, 400));

        $right = new Document();
        $right->addPage(new Page(200, 400));
        $right->setOpenAction(OpenAction::toPage(1));

        $left->appendDocument($right);

        $this->assertSame(2, $left->openAction()?->pageNumber);
    }

    public function testExtractPagesRemapsPageOpenAction(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addPage(new Page(200, 400));
        $document->setOpenAction(OpenAction::toPage(2));

        $extracted = $document->extractPages(2);

        $this->assertSame(1, $extracted->openAction()?->pageNumber);
    }
}
