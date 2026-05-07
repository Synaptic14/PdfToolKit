<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedOpenActionPreservationTest extends TestCase
{
    public function testImportedDestinationArrayOpenActionIsPreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction [3 0 R /XYZ 10 700 null] >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);

        $this->assertSame(1, $imported->document()->openAction()?->pageNumber);

        $bytes = $imported->save();

        $this->assertStringContainsString('/OpenAction [', $bytes);
        $this->assertStringContainsString('/XYZ 10 700 null', $bytes);
    }

    public function testImportedGoToOpenActionDictionaryIsPreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction << /S /GoTo /D [3 0 R /XYZ 5 350 null] >> >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())->loadString($pdf)->save();

        $this->assertStringContainsString('/OpenAction [', $bytes);
        $this->assertStringContainsString('/XYZ 5 350 null', $bytes);
        $this->assertStringNotContainsString('/S /GoTo', $bytes);
    }

    public function testImportedNamedDestinationOpenActionIsPreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction (intro) >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);

        $this->assertSame('intro', $imported->document()->openAction()?->destinationName);

        $bytes = $imported->save();

        $this->assertStringContainsString('/OpenAction (intro)', $bytes);
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
