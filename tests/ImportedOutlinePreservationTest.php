<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedOutlinePreservationTest extends TestCase
{
    public function testImportedOutlinesArePreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /Outlines 5 0 R /PageMode /UseOutlines >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Outlines /First 6 0 R /Last 6 0 R /Count 1 >>',
            6 => '<< /Title (Chapter One) /Parent 5 0 R /A << /S /URI /URI (https://example.test) >> >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $bytes = $imported->save();

        $this->assertStringContainsString('/Outlines', $bytes);
        $this->assertStringContainsString('/PageMode /UseOutlines', $bytes);
        $this->assertStringContainsString('/Title (Chapter One)', $bytes);
        $this->assertStringContainsString('/URI (https://example.test)', $bytes);
    }

    public function testImportedOutlineDestinationsAreReboundToSavedPageObjects(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /Outlines 5 0 R /PageMode /UseOutlines >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Outlines /First 6 0 R /Last 6 0 R /Count 1 >>',
            6 => '<< /Title (Page One) /Parent 5 0 R /Dest [3 0 R /XYZ 12 345 null] >>',
        ]);

        $bytes = (new Importer())->loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Page One)', $bytes);
        $this->assertStringContainsString('/Dest [4 0 R /XYZ 12 345 null]', $bytes);
        $this->assertStringNotContainsString('/Dest [3 0 R /XYZ 12 345 null]', $bytes);
    }

    public function testImportedOutlineGotoActionsAreReboundToSavedPageObjects(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /Outlines 5 0 R /PageMode /UseOutlines >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Type /Outlines /First 6 0 R /Last 6 0 R /Count 1 >>',
            6 => '<< /Title (GoTo) /Parent 5 0 R /A << /S /GoTo /D [3 0 R /Fit] >> >>',
        ]);

        $bytes = (new Importer())->loadString($pdf)->save();

        $this->assertStringContainsString('/Title (GoTo)', $bytes);
        $this->assertStringContainsString('/A << /S /GoTo /D [4 0 R /Fit] >>', $bytes);
        $this->assertStringNotContainsString('/A << /S /GoTo /D [3 0 R /Fit] >>', $bytes);
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
