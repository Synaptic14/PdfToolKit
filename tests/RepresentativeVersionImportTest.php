<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepresentativeVersionImportTest extends TestCase
{
    #[DataProvider('classicVersionProvider')]
    public function testImportsAndRewritesRepresentativeClassicPdfVersions(string $version): void
    {
        $pdf = $this->buildClassicPdf($version, [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /Font << /F1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 29 >>\nstream\nBT\n/F1 12 Tf\n(Test) Tj\nET\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);

        $bytes = (new Importer())->loadString($pdf)->save();

        $this->assertStringContainsString('(Test) Tj', $bytes);
        $this->assertStringContainsString('/BaseFont /Helvetica', $bytes);
    }

    public function testImportsAndRewritesRepresentativePdf17XrefStreamFixture(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for xref stream test fixtures.');
        }

        $bytes = (new Importer())->loadString($this->buildPdf17XrefStreamFixture())->save();

        $this->assertStringContainsString('/Type /Page', $bytes);
        $this->assertStringContainsString('%PDF-1.7', $bytes);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function classicVersionProvider(): iterable
    {
        yield 'pdf-1.3' => ['1.3'];
        yield 'pdf-1.4' => ['1.4'];
        yield 'pdf-1.8' => ['1.8'];
    }

    private function buildClassicPdf(string $version, array $objects): string
    {
        ksort($objects);

        $body = "%PDF-{$version}\n";
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

    private function buildPdf17XrefStreamFixture(): string
    {
        $objectStreamPayload = "3 0 << /Type /Page /Parent 2 0 R /Contents 6 0 R >>";
        $objectStream = zlib_encode($objectStreamPayload, ZLIB_ENCODING_DEFLATE);
        $pageContents = "BT\n(Version 1.7) Tj\nET";

        $body = "%PDF-1.7\n";
        $offset1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offset2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>\nendobj\n";
        $offset4 = strlen($body);
        $body .= "4 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($objectStream) . " >>\nstream\n" . $objectStream . "\nendstream\nendobj\n";
        $offset6 = strlen($body);
        $body .= "6 0 obj\n<< /Length " . strlen($pageContents) . " >>\nstream\n" . $pageContents . "\nendstream\nendobj\n";
        $offset7 = strlen($body);
        $xrefData = $this->buildXrefStreamData([
            [0, 0, 65535],
            [1, $offset1, 0],
            [1, $offset2, 0],
            [2, 4, 0],
            [1, $offset4, 0],
            [0, 0, 0],
            [1, $offset6, 0],
            [1, $offset7, 0],
        ]);
        $xrefStream = zlib_encode($xrefData, ZLIB_ENCODING_DEFLATE);
        $body .= "7 0 obj\n<< /Type /XRef /Size 8 /Root 1 0 R /W [1 4 2] /Index [0 8] /Filter /FlateDecode /Length " . strlen($xrefStream) . " >>\nstream\n" . $xrefStream . "\nendstream\nendobj\n";
        $body .= "startxref\n" . $offset7 . "\n%%EOF";

        return $body;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $entries
     */
    private function buildXrefStreamData(array $entries): string
    {
        $data = '';

        foreach ($entries as [$type, $field2, $field3]) {
            $data .= chr($type);
            $data .= pack('N', $field2);
            $data .= pack('n', $field3);
        }

        return $data;
    }
}
