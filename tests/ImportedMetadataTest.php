<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedMetadataTest extends TestCase
{
    public function testImportedInfoDictionaryPopulatesDocumentMetadata(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Title (Imported Title) /Author (Ada Lovelace) /Subject (Notes) /Keywords (math, engines) >>',
        ], infoObjectNumber: 5);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $metadata = $imported->document()->metadata();

        $this->assertSame('Imported Title', $metadata->title);
        $this->assertSame('Ada Lovelace', $metadata->author);
        $this->assertSame('Notes', $metadata->subject);
        $this->assertSame(['math', 'engines'], $metadata->keywords);

        $bytes = $imported->save();

        $this->assertStringContainsString('/Title (Imported Title)', $bytes);
        $this->assertStringContainsString('/Author (Ada Lovelace)', $bytes);
        $this->assertStringContainsString('/Subject (Notes)', $bytes);
        $this->assertStringContainsString('/Keywords (math, engines)', $bytes);
    }

    private function buildPdf(array $objects, ?int $infoObjectNumber = null): string
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

        $info = $infoObjectNumber === null ? '' : sprintf(' /Info %d 0 R', $infoObjectNumber);
        $body .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R" . $info . " >>\n";
        $body .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $body;
    }
}
