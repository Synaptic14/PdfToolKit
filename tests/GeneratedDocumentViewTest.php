<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\Page;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Import\Importer;
use PdfToolkit\Navigation\DocumentView;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedDocumentViewTest extends TestCase
{
    public function testGeneratedPageLayoutAndPageModeAreWrittenToCatalog(): void
    {
        $bytes = Pdf::new()
            ->pageLayout(DocumentView::PAGE_LAYOUT_TWO_COLUMN_LEFT)
            ->pageMode(DocumentView::PAGE_MODE_USE_THUMBS)
            ->addPage()
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/PageLayout /TwoColumnLeft', $bytes);
        $this->assertStringContainsString('/PageMode /UseThumbs', $bytes);
    }

    public function testGeneratedPageLayoutRejectsInvalidValue(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));

        $this->expectException(PdfException::class);

        $document->setPageLayout('invalid');
    }

    public function testGeneratedPageModeRejectsInvalidValue(): void
    {
        $document = new Document();
        $document->addPage(new Page(200, 400));

        $this->expectException(PdfException::class);

        $document->setPageMode('invalid');
    }

    public function testGeneratedPageModeOverridesImportedPageMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /PageMode /UseThumbs >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $document = (new Importer())->loadString($pdf)->document();
        $document->setPageMode(DocumentView::PAGE_MODE_FULL_SCREEN);
        $bytes = $document->save();

        $this->assertStringContainsString('/PageMode /FullScreen', $bytes);
        $this->assertStringNotContainsString('/PageMode /UseThumbs', $bytes);
    }

    /**
     * @param array<int, string> $objects
     */
    private function buildPdf(array $objects): string
    {
        ksort($objects);

        $body = "%PDF-1.7\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($body);
            $body .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
        }

        $xrefOffset = strlen($body);
        $body .= sprintf("xref\n0 %d\n", max(array_keys($objects)) + 1);
        $body .= "0000000000 65535 f \n";

        for ($i = 1; $i <= max(array_keys($objects)); $i++) {
            $offset = $offsets[$i] ?? 0;
            $state = isset($offsets[$i]) ? 'n' : 'f';
            $body .= sprintf("%010d 00000 %s \n", $offset, $state);
        }

        $body .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\n";
        $body .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $body;
    }
}
