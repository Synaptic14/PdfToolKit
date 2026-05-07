<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedOutputIntentsPreservationTest extends TestCase
{
    public function testImportedOutputIntentsArePreservedOnSave(): void
    {
        $icc = 'FAKEICC';
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /OutputIntents 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '[6 0 R]',
            6 => '<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB) /DestOutputProfile 7 0 R >>',
            7 => "<< /N 3 /Length " . strlen($icc) . " >>\nstream\n" . $icc . "\nendstream",
        ]);

        $bytes = (new Importer())->loadString($pdf)->save();

        $this->assertStringContainsString('/OutputIntents', $bytes);
        $this->assertStringContainsString('/Type /OutputIntent', $bytes);
        $this->assertStringContainsString('/OutputConditionIdentifier (sRGB)', $bytes);
        $this->assertStringContainsString('/DestOutputProfile', $bytes);
    }

    public function testInlineOutputIntentsArePreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /OutputIntents [<< /Type /OutputIntent /S /GTS_PDFA1 >>] >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $bytes = $imported->save();

        $this->assertSame([], $imported->report()->warnings);
        $this->assertStringContainsString('/OutputIntents', $bytes);
        $this->assertStringContainsString('/Type /OutputIntent', $bytes);
        $this->assertStringContainsString('/S /GTS_PDFA1', $bytes);
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
