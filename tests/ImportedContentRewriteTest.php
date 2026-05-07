<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedContentRewriteTest extends TestCase
{
    public function testReplaceTextRewritesImportedStreamContents(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 31 >>\nstream\nBT\n/F1 12 Tf\n(Old text) Tj\nET\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->pages()->page(1)->replaceText('Old', 'New');
        $source = $imported->document()->page(0)->importedSource();

        $this->assertNotNull($source);
        $this->assertStringContainsString('(New text) Tj', $source->contentStreams[0]->contents);
        $this->assertSame('New text', $source->contentStreams[0]->operations[2]->operands[0]);
    }

    public function testReplaceTextPersistsInSavedImportedDocument(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 31 >>\nstream\nBT\n/F1 12 Tf\n(Old text) Tj\nET\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->pages()->page(1)->replaceText('Old', 'Updated');
        $bytes = $imported->save();

        $this->assertStringContainsString('(Updated text) Tj', $bytes);
        $this->assertStringNotContainsString('(Old text) Tj', $bytes);
    }

    public function testReplaceTextRewritesTextShowingArrays(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 31 >>\nstream\nBT\n[(Old) 20 ( text)] TJ\nET\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);

        $imported->pages()->page(1)->replaceText('Old', 'New');
        $bytes = $imported->save();

        $this->assertStringContainsString('[(New) 20 ( text)] TJ', $bytes);
        $this->assertStringNotContainsString('[(Old) 20 ( text)] TJ', $bytes);
    }

    public function testTranslateRewritesCommonCoordinateOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 52 >>\nstream\n1 0 0 1 10 20 Tm\n10 20 m\n30 40 l\n50 60 70 80 re\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->pages()->page(1)->translate(5, 7);
        $contents = $imported->document()->page(0)->importedSource()?->contentStreams[0]->contents ?? '';

        $this->assertStringContainsString('1 0 0 1 15 27 Tm', $contents);
        $this->assertStringContainsString('15 27 m', $contents);
        $this->assertStringContainsString('35 47 l', $contents);
        $this->assertStringContainsString('55 67 70 80 re', $contents);
    }

    public function testTranslatePersistsInSavedImportedDocument(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 22 >>\nstream\n10 20 Td\n10 20 m\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->pages()->page(1)->translate(3, 4);
        $bytes = $imported->save();

        $this->assertStringContainsString('13 24 Td', $bytes);
        $this->assertStringContainsString('13 24 m', $bytes);
    }

    public function testSetTextPositionRewritesTextPositionOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 44 >>\nstream\n1 0 0 1 10 20 Tm\n30 40 Td\n50 60 TD\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setTextPosition(100, 200)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('1 0 0 1 100 200 Tm', $bytes);
        $this->assertStringContainsString('100 200 Td', $bytes);
        $this->assertStringContainsString('100 200 TD', $bytes);
    }

    public function testTranslateTextPositionRewritesOnlyTextPositionOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 43 >>\nstream\n1 0 0 1 10 20 Tm\n30 40 Td\n50 60 TD\n10 20 m\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->translateTextPosition(5, 7)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('1 0 0 1 15 27 Tm', $bytes);
        $this->assertStringContainsString('35 47 Td', $bytes);
        $this->assertStringContainsString('55 67 TD', $bytes);
        $this->assertStringContainsString('10 20 m', $bytes);
    }

    public function testSetTextMatrixRewritesTmOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 24 >>\nstream\n1 0 0 1 10 20 Tm\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setTextMatrix(0.5, 0, 0, 0.5, 100, 200)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('0.5 0 0 0.5 100 200 Tm', $bytes);
        $this->assertStringNotContainsString('1 0 0 1 10 20 Tm', $bytes);
    }

    public function testSetGraphicsMatrixRewritesCmOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 24 >>\nstream\n1 0 0 1 10 20 cm\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsMatrix(2, 0, 0, 2, 30, 40)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('2 0 0 2 30 40 cm', $bytes);
        $this->assertStringNotContainsString('1 0 0 1 10 20 cm', $bytes);
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
