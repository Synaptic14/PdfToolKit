<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class PredictorContentStreamImportTest extends TestCase
{
    public function testFlateStreamWithPngSubPredictorCanBeParsed(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for predictor fixtures.');
        }

        $content = "BT\n(PNG predictor) Tj\nET";
        $predicted = "\x01" . $this->pngSubPredict($content);
        $encoded = zlib_encode($predicted, ZLIB_ENCODING_DEFLATE);
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length " . strlen($encoded) . " /Filter /FlateDecode /DecodeParms << /Predictor 11 /Columns " . strlen($content) . " >> >>\nstream\n" . $encoded . "\nendstream",
        ]);

        $source = Pdf::loadString($pdf)->document()->page(0)->importedSource();

        self::assertNotNull($source);
        $this->assertSame($content, $source->contentStreams[0]->contents);
        $this->assertSame('Tj', $source->contentStreams[0]->operations[1]->operator);
    }

    public function testFlateStreamWithTiffPredictorCanBeParsed(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for predictor fixtures.');
        }

        $content = "BT\n(TIFF predictor) Tj\nET";
        $predicted = $this->pngSubPredict($content);
        $encoded = zlib_encode($predicted, ZLIB_ENCODING_DEFLATE);
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length " . strlen($encoded) . " /Filter /FlateDecode /DecodeParms << /Predictor 2 /Columns " . strlen($content) . " >> >>\nstream\n" . $encoded . "\nendstream",
        ]);

        $source = Pdf::loadString($pdf)->document()->page(0)->importedSource();

        self::assertNotNull($source);
        $this->assertSame($content, $source->contentStreams[0]->contents);
        $this->assertSame('Tj', $source->contentStreams[0]->operations[1]->operator);
    }

    private function pngSubPredict(string $contents): string
    {
        $predicted = '';
        $previous = 0;

        foreach (str_split($contents) as $character) {
            $value = ord($character);
            $predicted .= chr(($value - $previous) & 0xFF);
            $previous = $value;
        }

        return $predicted;
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
