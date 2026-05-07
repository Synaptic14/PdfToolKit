<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Core\PdfException;
use PHPUnit\Framework\TestCase;

final class ImportedPageBoxesTest extends TestCase
{
    public function testImportedInheritedPageBoxesArePreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] /CropBox [10 20 290 480] /TrimBox [20 30 280 470] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $this->assertSame([
            'CropBox' => [10.0, 20.0, 290.0, 480.0],
            'TrimBox' => [20.0, 30.0, 280.0, 470.0],
        ], $imported->document()->page(0)->pageBoxes());

        $bytes = $imported->save();

        $this->assertStringContainsString('/CropBox [10 20 290 480]', $bytes);
        $this->assertStringContainsString('/TrimBox [20 30 280 470]', $bytes);
    }

    public function testImportedPageEditorCanSetPageBoxFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setPageBox('CropBox', [15, 25, 285, 475])
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/CropBox [15 25 285 475]', $bytes);
    }

    public function testImportedPageEditorCanSetPageSizeFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setSize(320, 640)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/MediaBox [0 0 320.000000 640.000000]', $bytes);
    }

    public function testImportedPageEditorRejectsInvalidPageSize(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $this->expectException(PdfException::class);

        (new Importer())->loadString($pdf)->pages()->page(1)->setSize(320, 0);
    }

    public function testImportedPageEditorCanSetNamedPageBoxesFluently(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setCropBox([15, 25, 285, 475])
            ->setTrimBox([20, 30, 280, 470])
            ->setBleedBox([10, 20, 290, 480])
            ->setArtBox([25, 35, 275, 465])
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/CropBox [15 25 285 475]', $bytes);
        $this->assertStringContainsString('/TrimBox [20 30 280 470]', $bytes);
        $this->assertStringContainsString('/BleedBox [10 20 290 480]', $bytes);
        $this->assertStringContainsString('/ArtBox [25 35 275 465]', $bytes);
    }

    public function testPageBoxNamesAreNormalizedAndValidated(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported->pages()->page(1)->setPageBox('cropbox', [1, 2, 3, 4]);

        $this->assertArrayHasKey('CropBox', $imported->document()->page(0)->pageBoxes());
    }

    public function testInvalidPageBoxShapeIsRejected(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $this->expectException(PdfException::class);

        (new Importer())->loadString($pdf)->pages()->page(1)->setCropBox([10, 20, 5, 40]);
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
