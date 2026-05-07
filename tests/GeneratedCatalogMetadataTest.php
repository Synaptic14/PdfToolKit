<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class GeneratedCatalogMetadataTest extends TestCase
{
    public function testGeneratesCatalogMetadataXmpFromDocumentMetadata(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'Invoice 1001', author: 'PdfToolkit', subject: 'Billing', keywords: ['invoice', 'paid'])
            ->catalogMetadata()
            ->addPage()
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Metadata', $bytes);
        $this->assertStringContainsString('/Subtype /XML', $bytes);
        $this->assertStringContainsString('<dc:title>', $bytes);
        $this->assertStringContainsString('Invoice 1001', $bytes);
        $this->assertStringContainsString('<dc:creator>', $bytes);
        $this->assertStringContainsString('<pdf:Keywords>', $bytes);
        $this->assertStringContainsString('<rdf:li>invoice</rdf:li>', $bytes);
    }

    public function testGeneratedCatalogMetadataEscapesXmlValues(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'A & B < C', author: '"Ada"')
            ->catalogMetadata()
            ->addPage()
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('A &amp; B &lt; C', $bytes);
        $this->assertStringContainsString('&quot;Ada&quot;', $bytes);
    }

    public function testGeneratedCatalogMetadataOverridesImportedMetadataStream(): void
    {
        $xmp = '<?xpacket begin=""?><x:xmpmeta><pdf:Producer>Imported</pdf:Producer></x:xmpmeta>';
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /Metadata 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => "<< /Type /Metadata /Subtype /XML /Length " . strlen($xmp) . " >>\nstream\n" . $xmp . "\nendstream",
        ]);

        $document = (new Importer())->loadString($pdf)->document();
        $document->setMetadata(new \PdfToolkit\Core\DocumentMetadata(title: 'Generated'));
        $document->setGenerateCatalogMetadata();
        $bytes = $document->save();

        $this->assertStringContainsString('<dc:title>', $bytes);
        $this->assertStringContainsString('Generated', $bytes);
        $this->assertStringNotContainsString('<pdf:Producer>Imported</pdf:Producer>', $bytes);
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
