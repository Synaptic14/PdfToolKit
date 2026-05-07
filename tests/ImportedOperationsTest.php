<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Parser\PdfName;
use PHPUnit\Framework\TestCase;

final class ImportedOperationsTest extends TestCase
{
    public function testImportedPageEditorExposesParsedOperations(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 29 >>\nstream\nBT\n/F1 12 Tf\n(Old) Tj\nET\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $operations = $imported->pages()->page(1)->operations();

        $this->assertCount(4, $operations);
        $this->assertSame('BT', $operations[0]->operator);
        $this->assertSame('Tf', $operations[1]->operator);
        $this->assertEquals([new PdfName('F1'), 12], $operations[1]->operands);
        $this->assertSame('Tj', $operations[2]->operator);
        $this->assertSame(['Old'], $operations[2]->operands);
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
