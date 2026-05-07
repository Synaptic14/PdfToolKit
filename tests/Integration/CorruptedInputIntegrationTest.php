<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Pdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorruptedInputIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fatalMutationProvider(): iterable
    {
        $fixture = file_get_contents(dirname(__DIR__, 2) . '/examples/f1099msc.pdf');

        if ($fixture === false) {
            throw new \RuntimeException('Unable to load f1099msc.pdf fixture for mutation tests.');
        }

        yield 'truncated-real-fixture' => [
            substr($fixture, 0, max(0, strlen($fixture) - 200)),
            'Unable to locate startxref marker.',
        ];

        yield 'invalid-startxref-pointer' => [
            (string) preg_replace('/startxref\s+\d+/', "startxref\n999999999", $fixture, 1),
            'Invalid xref stream object header.',
        ];
    }

    #[DataProvider('fatalMutationProvider')]
    public function testCorruptedInputsFailWithControlledPdfExceptions(string $pdf, string $messageFragment): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage($messageFragment);

        Pdf::loadString($pdf);
    }

    public function testUnsupportedFilteredPageContentStreamIsDowngradedToWarningAndPreserved(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 200] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 4 /Filter /BogusDecode >>\nstream\nABCD\nendstream",
        ]);

        $imported = Pdf::loadString($pdf);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertCount(1, $imported->report()->warnings);
        $this->assertStringContainsString('Unsupported stream filter: BogusDecode', $imported->report()->warnings[0]);

        $saved = $imported->save();

        $this->assertSame(1, Pdf::loadString($saved)->report()->pageCount);
        $this->assertStringContainsString('/Filter /BogusDecode', $saved);
    }

    public function testInvalidAsciiHexFilteredPageContentStreamIsDowngradedToWarningAndPreserved(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 200] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 3 /Filter /ASCIIHexDecode >>\nstream\nGG>\nendstream",
        ]);

        $imported = Pdf::loadString($pdf);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertCount(1, $imported->report()->warnings);
        $this->assertStringContainsString('ASCIIHexDecode stream contains non-hex characters.', $imported->report()->warnings[0]);

        $saved = $imported->save();

        $this->assertSame(1, Pdf::loadString($saved)->report()->pageCount);
        $this->assertStringContainsString('/Filter /ASCIIHexDecode', $saved);
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
