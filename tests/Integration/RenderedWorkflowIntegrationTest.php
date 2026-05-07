<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Import\Importer;
use PdfToolkit\Tests\Support\ExternalPdfPageRenderer;
use PHPUnit\Framework\TestCase;

final class RenderedWorkflowIntegrationTest extends TestCase
{
    private ExternalPdfPageRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ExternalPdfPageRenderer();

        if (!$this->renderer->isAvailable()) {
            $this->markTestSkipped('pdftoppm is required for rendered workflow integration tests.');
        }
    }

    public function testGeneratedInlineSvgProducesVisibleRenderedPixels(): void
    {
        $bytes = $this->withSvgEnabled(function (): string {
            return \PdfToolkit\Pdf::new()
                ->addPage(width: 300, height: 200)
                ->image(ImagePlacement::svgData($this->signatureSvgFixture(), 40, 40, 120, 40))
                ->endPage()
                ->build()
                ->save();
        });

        $page = $this->renderer->renderFirstPage($bytes);

        $this->assertGreaterThan(150, $page->countNonWhitePixelsInRect(40, 40, 120, 40));
        $this->assertSame(0, $page->countNonWhitePixelsInRect(220, 140, 30, 20));
    }

    public function testImportedInlineSvgRemainsVisibleWhenTemplateLeavesClippingPathActive(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 200] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 18 >>\nstream\n0 0 10 10 re\nW\nn\nendstream",
        ]);

        $bytes = $this->withSvgEnabled(function () use ($pdf): string {
            $imported = (new Importer())->loadString($pdf);
            $imported->document()->page(0)->addImage(ImagePlacement::svgData($this->signatureSvgFixture(), 72, 72, 80, 28));

            return $imported->save();
        });

        $page = $this->renderer->renderFirstPage($bytes);

        $this->assertGreaterThan(100, $page->countNonWhitePixelsInRect(72, 72, 80, 28));
        $this->assertSame(0, $page->countNonWhitePixelsInRect(160, 160, 20, 20));
    }

    public function testRegeneratedImportedTextFieldAppearanceProducesVisibleRenderedPixels(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (existing_name) /V (Ada) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setText('existing_name', 'Grace Hopper')
            ->regenerateAppearances()
            ->done()
            ->save();

        $page = $this->renderer->renderFirstPage($bytes);
        [$x, $y, $width, $height] = $this->pdfRectToRenderedRect([72, 300, 160, 324], 400);

        $this->assertGreaterThan(100, $page->countNonWhitePixelsInRect($x, $y, $width, $height));
    }

    public function testRegeneratedImportedCheckboxAppearanceProducesVisibleRenderedPixels(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /P 3 0 R /Rect [72 300 92 320] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setCheckbox('accepted', true)
            ->regenerateAppearances()
            ->done()
            ->save();

        $page = $this->renderer->renderFirstPage($bytes);
        [$x, $y, $width, $height] = $this->pdfRectToRenderedRect([72, 300, 92, 320], 400);

        $this->assertGreaterThan(20, $page->countNonWhitePixelsInRect($x, $y, $width, $height));
    }

    public function testImportedOverlayOnSecondPageProducesVisibleRenderedPixels(): void
    {
        $bytes = \PdfToolkit\Pdf::load(dirname(__DIR__, 2) . '/examples/f1099msc.pdf')
            ->pages()
            ->page(2)
            ->overlayText('Page 2 Visual Overlay', x: 72, y: 72, fontSize: 16)
            ->done()
            ->done()
            ->save();

        $page = $this->renderer->renderPage($bytes, 2);

        $this->assertGreaterThan(150, $page->countNonWhitePixelsInRect(72, 72, 160, 24));
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

    /**
     * @param list<int|float> $rect
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function pdfRectToRenderedRect(array $rect, float $pageHeight): array
    {
        return [
            (float) $rect[0],
            $pageHeight - (float) $rect[3],
            (float) $rect[2] - (float) $rect[0],
            (float) $rect[3] - (float) $rect[1],
        ];
    }
}
