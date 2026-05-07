<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedAnnotationPreservationTest extends TestCase
{
    public function testImportedPageAnnotationsArePreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Remember this) >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $bytes = $imported->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Subtype /Text', $bytes);
        $this->assertStringContainsString('/Contents (Remember this)', $bytes);
    }

    public function testGeneratedAnnotationsMergeWithImportedAnnotations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Remember this) >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->document()->page(0)->addTextAnnotation(new TextAnnotation('New note', 50, 50));
        $bytes = $imported->save();

        $this->assertStringContainsString('/Contents (Remember this)', $bytes);
        $this->assertStringContainsString('/Contents (New note)', $bytes);
    }

    public function testGeneratedLinksMergeWithImportedAnnotations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Existing note) >>',
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported->document()->page(0)->addLinkAnnotation(new LinkAnnotation('https://example.test', 50, 50, 90, 12));
        $bytes = $imported->save();

        $this->assertStringContainsString('/Contents (Existing note)', $bytes);
        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/URI (https://example.test)', $bytes);
    }

    public function testImportedPageEditorCanAddAnnotationsFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->addTextAnnotation('Editor note', 20, 20)
            ->addLink('https://example.test/editor', 40, 40, 100, 20)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Contents (Editor note)', $bytes);
        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/URI (https://example.test/editor)', $bytes);
    }

    public function testImportedPageEditorCanAddInternalPageLinksFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /Contents 6 0 R >>',
            6 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->addPageLink(2, 40, 40, 100, 20, left: 5, top: 350)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/Dest [', $bytes);
        $this->assertStringContainsString('/XYZ 5 50 null', $bytes);
    }

    public function testImportedPageEditorCanAddNamedDestinationLinksFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported
            ->pages()
            ->page(1)
            ->addNamedDestinationLink('intro', 40, 40, 100, 20)
            ->done();
        $imported->document()->addNamedDestination('intro', 1);
        $bytes = $imported->save();

        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/Dest (intro)', $bytes);
        $this->assertStringContainsString('/Names', $bytes);
        $this->assertStringContainsString('/Dests', $bytes);
    }

    public function testImportedPageEditorCanClearImportedAnnotations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Remove me) /AP 6 0 R >>',
            6 => '<< /N 7 0 R >>',
            7 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->clearImportedAnnotations()
            ->done()
            ->done()
            ->save();

        $this->assertStringNotContainsString('/Annots', $bytes);
        $this->assertStringNotContainsString('(Remove me)', $bytes);
        $this->assertStringNotContainsString('/AP', $bytes);
    }

    public function testClearingImportedAnnotationsKeepsGeneratedAnnotations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Remove me) >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->clearImportedAnnotations()
            ->addTextAnnotation('Keep me', 50, 50)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Contents (Keep me)', $bytes);
        $this->assertStringNotContainsString('/Contents (Remove me)', $bytes);
    }

    public function testImportedPageEditorCanClearImportedAnnotationsBySubtype(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R 6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Keep me) >>',
            6 => '<< /Type /Annot /Subtype /Link /Rect [50 50 80 70] /A 7 0 R >>',
            7 => '<< /S /URI /URI (https://example.test/remove) >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->clearImportedAnnotationsBySubtype('Link')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Subtype /Text', $bytes);
        $this->assertStringContainsString('/Contents (Keep me)', $bytes);
        $this->assertStringNotContainsString('/Subtype /Link', $bytes);
        $this->assertStringNotContainsString('/URI (https://example.test/remove)', $bytes);
    }

    public function testImportedPageEditorCanClearImportedLinkAnnotationsConvenienceHelper(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R 6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /A 7 0 R >>',
            6 => '<< /Type /Annot /Subtype /Text /Rect [50 50 80 70] /Contents (Still here) >>',
            7 => '<< /S /URI /URI (https://example.test/link) >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->clearImportedLinkAnnotations()
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Subtype /Text', $bytes);
        $this->assertStringContainsString('/Contents (Still here)', $bytes);
        $this->assertStringNotContainsString('/Subtype /Link', $bytes);
        $this->assertStringNotContainsString('/URI (https://example.test/link)', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedTextAnnotationContents(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Text /Rect [20 20 40 40] /Contents (Remember this) >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedTextAnnotationContents('Remember', 'Updated')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Contents (Updated this)', $bytes);
        $this->assertStringNotContainsString('/Contents (Remember this)', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedLinkUrisInReferencedActions(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [5 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /A 6 0 R >>',
            6 => '<< /S /URI /URI (https://example.test/old) >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedLinkUris('/old', '/new')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/URI (https://example.test/new)', $bytes);
        $this->assertStringNotContainsString('/URI (https://example.test/old)', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedLinkUrisInInlineActions(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /A << /S /URI /URI (https://example.test/inline-old) >> >>] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedLinkUris('inline-old', 'inline-new')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/URI (https://example.test/inline-new)', $bytes);
        $this->assertStringNotContainsString('/URI (https://example.test/inline-old)', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedDirectPageLinkDestinations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /Contents 6 0 R >>',
            6 => "<< /Length 0 >>\nstream\n\nendstream",
            7 => '<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /Dest [3 0 R /XYZ 12 345 null] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedPageLinkDestinations(2, left: 10, top: 200, zoom: 1.5)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Dest [5 0 R /XYZ 10 200 1.5]', $bytes);
        $this->assertStringNotContainsString('/Dest [3 0 R /XYZ 12 345 null]', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedGoToActionPageLinkDestinations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /Contents 6 0 R >>',
            6 => "<< /Length 0 >>\nstream\n\nendstream",
            7 => '<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /A 8 0 R >>',
            8 => '<< /S /GoTo /D [3 0 R /XYZ 12 345 null] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedPageLinkDestinations(2, left: 15, top: 150)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/D [5 0 R /XYZ 15 150 null]', $bytes);
        $this->assertStringNotContainsString('/D [3 0 R /XYZ 12 345 null]', $bytes);
    }

    public function testImportedPageEditorCanReplaceImportedInlinePageLinkDestinations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [<< /Type /Annot /Subtype /Link /Rect [20 20 40 40] /Dest [3 0 R /XYZ 12 345 null] >>] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /Contents 6 0 R >>',
            6 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->replaceImportedPageLinkDestinations(2, top: 99)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/Dest [5 0 R /XYZ null 99 null]', $bytes);
        $this->assertStringNotContainsString('/Dest [3 0 R /XYZ 12 345 null]', $bytes);
    }

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
