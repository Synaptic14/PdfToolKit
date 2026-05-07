<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class LoadStringTest extends TestCase
{
    public function testPdfFacadeLoadsRawPdfBytes(): void
    {
        $imported = Pdf::loadString($this->singlePagePdf());

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertSame(200.0, $imported->document()->page(0)->width());
        $this->assertNull($imported->report()->security);
    }

    public function testImporterLoadsRawPdfBytes(): void
    {
        $imported = (new Importer())->loadString($this->singlePagePdf());

        $this->assertSame('1.7', $imported->report()->version);
        $this->assertSame(400.0, $imported->document()->page(0)->height());
    }

    private function singlePagePdf(): string
    {
        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);
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
