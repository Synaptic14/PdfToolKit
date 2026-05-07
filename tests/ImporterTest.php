<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Parser\PdfParser;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    public function testImportedPagesCarrySourceInformation(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 10 >>\nstream\nq\nQ\nendstream",
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);

        $imported = (new Importer())->load($path);
        unlink($path);

        $source = $imported->document()->page(0)->importedSource();

        $this->assertNotNull($source);
        $this->assertSame(3, $source->objectNumber);
        $this->assertCount(1, $source->contentStreams);
        $this->assertSame("q\nQ", $source->contentStreams[0]->contents);
    }

    public function testImporterAcceptsExplicitParserDependency(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
        ]);

        $imported = (new Importer(new PdfParser()))->loadString($pdf);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertCount(1, $imported->document()->pages());
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
