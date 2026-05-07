<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Writer\WriteOptions;
use PHPUnit\Framework\TestCase;

final class WorkflowSmokeIntegrationTest extends TestCase
{
    public function testGeneratedDocumentWorkflowSupportsInlineSvgAndRoundTrip(): void
    {
        $bytes = $this->withSvgEnabled(function (): string {
            return Pdf::new()
                ->metadata(title: 'Workflow Generated', author: 'PdfToolkit')
                ->catalogMetadata()
                ->addPage(width: 420, height: 300)
                ->text(new TextRun('Workflow Smoke', 72, 72, 18))
                ->image(ImagePlacement::svgData($this->signatureSvgFixture(), 120, 110, 150, 48))
                ->endPage()
                ->build()
                ->save();
        });

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('/Subtype /Image', $bytes);

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame(1, $reloaded->report()->pageCount);
        $this->assertSame([], $reloaded->report()->warnings);
        $this->assertStringContainsString('(Workflow Smoke) Tj', $reloaded->save());
    }

    public function testImportedOverlayWorkflowSupportsMultiPageEditsAndRoundTrip(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));

        $bytes = $imported
            ->pages()
            ->page(1)
            ->overlayText('Workflow Overlay', x: 72, y: 72, fontSize: 16)
            ->done()
            ->page(2)
            ->overlayText('Second Page Overlay', x: 90, y: 90, fontSize: 12)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('Workflow Overlay', $bytes);
        $this->assertStringContainsString('Second Page Overlay', $bytes);

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame(6, $reloaded->report()->pageCount);
        $this->assertSame([], $reloaded->report()->warnings);
    }

    public function testImportedOverlayAndFormFillWorkflowSupportsReparse(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));
        $fieldNames = $imported->form()->fieldNames();

        $this->assertGreaterThan(100, count($fieldNames));

        $bytes = $imported
            ->pages()
            ->page(1)
            ->overlayText('Workflow Overlay', x: 72, y: 72, fontSize: 16)
            ->done()
            ->done()
            ->form()
            ->setText($fieldNames[0], 'Workflow Fill')
            ->regenerateAppearances()
            ->done()
            ->save();

        $this->assertStringContainsString('Workflow Overlay', $bytes);
        $this->assertStringContainsString('Workflow Fill', $bytes);

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame(6, $reloaded->report()->pageCount);
        $this->assertGreaterThan(100, count($reloaded->form()->fieldNames()));
    }

    public function testImportedEncryptedWorkflowSupportsPasswordProtectedRoundTrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 workflow smoke tests.');
        }

        $bytes = Pdf::load($this->fixturePath('f1099msc.pdf'))
            ->pages()
            ->page(1)
            ->overlayText('Encrypted Overlay', x: 72, y: 72, fontSize: 16)
            ->done()
            ->done()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringNotContainsString('Encrypted Overlay', $bytes);

        $imported = Pdf::loadString($bytes, 'user-secret');

        $this->assertSame(6, $imported->report()->pageCount);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
        $this->assertTrue($imported->report()->security->authenticatedAsUser());
        $this->assertTrue($imported->report()->security->openedWithPassword);

        $roundTripped = $imported->save();

        $this->assertStringContainsString('Encrypted Overlay', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
        $this->assertSame(6, Pdf::loadString($roundTripped)->report()->pageCount);
    }

    private function fixturePath(string $filename): string
    {
        return dirname(__DIR__, 2) . '/examples/' . $filename;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withSvgEnabled(callable $callback): mixed
    {
        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

        try {
            return $callback();
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    private function signatureSvgFixture(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 70">
  <path d="M12 48 C25 20, 45 20, 60 48 S95 76, 112 44 S148 10, 170 42 S205 74, 228 28"
        fill="none"
        stroke="#111111"
        stroke-width="5"
        stroke-linecap="round"
        stroke-linejoin="round"/>
</svg>
SVG;
    }
}
