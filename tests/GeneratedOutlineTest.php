<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedOutlineTest extends TestCase
{
    public function testGeneratedOutlinesPointToPages(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->addPage()
            ->endPage()
            ->outline('Introduction', 1)
            ->outline('Appendix', 2)
            ->build()
            ->save();

        $this->assertStringContainsString('/Outlines', $bytes);
        $this->assertStringContainsString('/PageMode /UseOutlines', $bytes);
        $this->assertStringContainsString('/Type /Outlines', $bytes);
        $this->assertStringContainsString('/Title (Introduction)', $bytes);
        $this->assertStringContainsString('/Title (Appendix)', $bytes);
        $this->assertStringContainsString('/Dest [', $bytes);
        $this->assertStringContainsString('/Count 2', $bytes);
    }

    public function testGeneratedOutlinesCanBeNested(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->addPage()
            ->endPage()
            ->outline('Chapter', 1)
            ->outline('Section', 2, level: 1)
            ->build()
            ->save();

        $this->assertStringContainsString('/Title (Chapter)', $bytes);
        $this->assertStringContainsString('/Title (Section)', $bytes);
        $this->assertStringContainsString('/First', $bytes);
        $this->assertStringContainsString('/Last', $bytes);
        $this->assertStringContainsString('/Parent', $bytes);
        $this->assertStringContainsString('/Count 1', $bytes);
    }

    public function testGeneratedOutlinesCanSetDestinationView(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->endPage()
            ->outline('Zoomed', 1, left: 12.0, top: 345.0, zoom: 1.5)
            ->build()
            ->save();

        $this->assertStringContainsString('/Dest [', $bytes);
        $this->assertStringContainsString('/XYZ 12 497 1.5', $bytes);
    }

    public function testGeneratedOutlineRejectsMissingPagesOnSave(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));
        $document->addOutline('Missing', 2);

        $this->expectException(\LogicException::class);

        $document->save();
    }
}
