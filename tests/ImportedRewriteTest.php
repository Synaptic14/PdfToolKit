<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Text\TextRun;
use PHPUnit\Framework\TestCase;

final class ImportedRewriteTest extends TestCase
{
    public function testImportedDocumentSavePreservesOriginalContentAndOverlay(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /Font << /F1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 29 >>\nstream\nBT\n/F1 12 Tf\n(Old) Tj\nET\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);

        $imported = (new Importer())->load($path);
        unlink($path);

        $imported->document()->page(0)->addText(new TextRun('New', 72, 72));
        $bytes = $imported->save();

        $this->assertStringContainsString('(Old) Tj', $bytes);
        $this->assertStringContainsString('(New) Tj', $bytes);
        $this->assertStringContainsString('/BaseFont /Helvetica', $bytes);
    }

    public function testImportedPageEditorCanOverlayShapesAndRedactionAreas(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported
            ->pages()
            ->page(1)
            ->overlayLine(10, 20, 30, 40, width: 2, strokeColor: Color::white())
            ->overlayRectangle(50, 60, 70, 80, strokeColor: Color::black(), fillColor: new Color(1, 0, 0))
            ->redactArea(12, 34, 56, 78)
            ->done();
        $bytes = $imported->save();

        $this->assertStringContainsString('1 G', $bytes);
        $this->assertStringContainsString('10 380 m', $bytes);
        $this->assertStringContainsString('30 360 l', $bytes);
        $this->assertStringContainsString('50 260 70 80 re', $bytes);
        $this->assertStringContainsString('12 288 56 78 re', $bytes);
        $this->assertStringContainsString('0 g', $bytes);
    }

    public function testImportedDocumentCanOverlayInlineSvgImageOnImportedPage(): void
    {
        if (trim((string) shell_exec('command -v magick 2>/dev/null')) === '') {
            $this->markTestSkipped('ImageMagick is required for SVG fixture rendering.');
        }

        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        try {
            $imported = (new Importer())->loadString($pdf);
            $imported->document()->page(0)->addImage(ImagePlacement::svgData($this->svgFixture(), 10, 20, 30, 40));
            $bytes = $imported->save();

            $this->assertStringContainsString('/Subtype /Image', $bytes);
            $this->assertStringContainsString('/PT_Im1 Do', $bytes);
            $this->assertStringContainsString('/XObject << /PT_Im1', $bytes);
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testImportedOverlayIsolatedFromImportedGraphicsState(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 18 >>\nstream\n0 0 10 10 re\nW\nn\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported->document()->page(0)->addText(new TextRun('Overlay', 72, 72));
        $bytes = $imported->save();

        $this->assertMatchesRegularExpression(
            '/\\/Contents \\[(\\d+) 0 R (\\d+) 0 R (\\d+) 0 R (\\d+) 0 R\\]/',
            $bytes,
            'Expected imported page contents to be wrapped with q/Q around the imported stream before the overlay stream.'
        );
        preg_match('/\\/Contents \\[(\\d+) 0 R (\\d+) 0 R (\\d+) 0 R (\\d+) 0 R\\]/', $bytes, $matches);

        $this->assertIsArray($matches);
        $this->assertCount(5, $matches);
        [$full, $pushObjectId, $importedObjectId, $popObjectId, $overlayObjectId] = $matches;

        $this->assertStringContainsString($pushObjectId . " 0 obj\n<< /Length 1 >>\nstream\nq\nendstream", $bytes);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($importedObjectId . " 0 obj\n", '/') . '<< \\/Length \\d+ >>' . preg_quote("\nstream\n0 0 10 10 re\nW\nn\nendstream", '/') . '/',
            $bytes
        );
        $this->assertStringContainsString($popObjectId . " 0 obj\n<< /Length 1 >>\nstream\nQ\nendstream", $bytes);
        $this->assertStringContainsString($overlayObjectId . " 0 obj", $bytes);
        $this->assertStringContainsString('(Overlay) Tj', $bytes);
    }

    public function testImportedPageEditorCanRewriteGraphicsStateOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 36 >>\nstream\n1 w\n0 G\n0 g\n10 10 m\n20 20 l\nS\nendstream",
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported
            ->pages()
            ->page(1)
            ->setLineWidth(2.5)
            ->setStrokeColor(new Color(1, 0, 0))
            ->setFillColor(Color::white())
            ->done();
        $bytes = $imported->save();

        $this->assertStringContainsString('2.5 w', $bytes);
        $this->assertStringContainsString('1 0 0 RG', $bytes);
        $this->assertStringContainsString('1 g', $bytes);
        $this->assertStringContainsString('10 10 m', $bytes);
    }

    public function testImportedPageEditorCanRewriteGrayAndCmykColors(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 43 >>\nstream\n0 0 0 RG\n0 0 0 rg\n0 0 0 1 K\n0 0 0 1 k\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeColor(Color::gray(0.5))
            ->setFillColor(Color::cmyk(0.1, 0.2, 0.3, 0.4))
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('0.5 G', $bytes);
        $this->assertStringContainsString('0.1 0.2 0.3 0.4 k', $bytes);
    }

    public function testImportedPageEditorCanRewriteAdvancedGraphicsStateOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 60 >>\nstream\n0 J\n0 j\n10 M\n[] 0 d\n/RelativeColorimetric ri\n1 i\n10 10 m\n20 20 l\nS\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setLineCap(2)
            ->setLineJoin(1)
            ->setMiterLimit(5.5)
            ->setDashPattern([3, 1.5], 2)
            ->setRenderingIntent('Perceptual')
            ->setFlatness(0.5)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('2 J', $bytes);
        $this->assertStringContainsString('1 j', $bytes);
        $this->assertStringContainsString('5.5 M', $bytes);
        $this->assertStringContainsString('[3 1.5] 2 d', $bytes);
        $this->assertStringContainsString('/Perceptual ri', $bytes);
        $this->assertStringContainsString('0.5 i', $bytes);
    }

    public function testImportedPageEditorCanRewritePathPaintingAndClippingOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 58 >>\nstream\n10 10 m\n20 20 l\nS\n30 30 40 50 re\nf*\n0 0 100 100 re\nW*\nn\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setPathPaintingOperator('B')
            ->setClippingRule('nonzero')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString("10 10 m\n20 20 l\nB", $bytes);
        $this->assertStringContainsString("30 30 40 50 re\nB", $bytes);
        $this->assertStringContainsString("0 0 100 100 re\nW\nB", $bytes);
        $this->assertStringNotContainsString("\nS\n", $bytes);
        $this->assertStringNotContainsString("\nf*\n", $bytes);
        $this->assertStringNotContainsString("\nW*\n", $bytes);
    }

    public function testImportedPageEditorCanRewriteAutoClosePathPaintingOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 38 >>\nstream\n10 10 m\n20 20 l\nS\n30 30 m\n40 40 l\nB\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setAutoClosePathPainting()
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString("10 10 m\n20 20 l\ns", $bytes);
        $this->assertStringContainsString("30 30 m\n40 40 l\nb", $bytes);
        $this->assertStringNotContainsString("\nS\n", $bytes);
        $this->assertStringNotContainsString("\nB\n", $bytes);
    }

    public function testImportedPageEditorCanDisableAutoClosePathPaintingOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 40 >>\nstream\n10 10 m\n20 20 l\ns\n30 30 m\n40 40 l\nb*\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setAutoClosePathPainting(false)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString("10 10 m\n20 20 l\nS", $bytes);
        $this->assertStringContainsString("30 30 m\n40 40 l\nB*", $bytes);
        $this->assertStringNotContainsString("\ns\n", $bytes);
        $this->assertStringNotContainsString("\nb*\n", $bytes);
    }

    public function testImportedPageEditorCanRewriteTextStateOperators(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 61 >>\nstream\nBT\n/F1 12 Tf\n0 Tc\n0 Tw\n100 Tz\n0 TL\n0 Ts\n0 Tr\n(Test) Tj\nET\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setFontSize(18)
            ->setCharacterSpacing(1.25)
            ->setWordSpacing(2.5)
            ->setHorizontalScaling(85)
            ->setLeading(14)
            ->setTextRise(3)
            ->setTextRenderingMode(2)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/F1 18 Tf', $bytes);
        $this->assertStringContainsString('1.25 Tc', $bytes);
        $this->assertStringContainsString('2.5 Tw', $bytes);
        $this->assertStringContainsString('85 Tz', $bytes);
        $this->assertStringContainsString('14 TL', $bytes);
        $this->assertStringContainsString('3 Ts', $bytes);
        $this->assertStringContainsString('2 Tr', $bytes);
        $this->assertStringContainsString('(Test) Tj', $bytes);
    }

    public function testImportedPageEditorRejectsInvalidHorizontalScaling(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 14 >>\nstream\n100 Tz\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setHorizontalScaling(0);
    }

    public function testImportedPageEditorRejectsInvalidTextRenderingMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 12 >>\nstream\n0 Tr\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setTextRenderingMode(8);
    }

    public function testImportedPageEditorRejectsInvalidFlatness(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 11 >>\nstream\n1 i\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setFlatness(101);
    }

    public function testImportedPageEditorRejectsInvalidFontSize(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 18 >>\nstream\n/F1 12 Tf\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setFontSize(0);
    }

    public function testImportedPageEditorRejectsInvalidPathPaintingOperator(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 12 >>\nstream\nS\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setPathPaintingOperator('Q');
    }

    public function testImportedPageEditorRejectsInvalidClippingRule(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 13 >>\nstream\nW\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setClippingRule('odd');
    }

    public function testImportedPageEditorCanRenameImportedResourceReferences(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /Font << /F1 5 0 R >> /XObject << /Im1 6 0 R >> /ExtGState << /GS1 7 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 31 >>\nstream\nBT\n/F1 12 Tf\nET\n/GS1 gs\n/Im1 Do\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => "<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>\nstream\n\x00\nendstream",
            7 => '<< /Type /ExtGState /ca 0.5 /CA 0.5 >>',
        ]);

        $imported = (new Importer())->loadString($pdf);
        $imported
            ->pages()
            ->page(1)
            ->renameFontResource('F1', 'Body')
            ->renameGraphicsStateResource('GS1', 'Fade')
            ->renameXObjectResource('Im1', 'Logo')
            ->done();
        $bytes = $imported->save();

        $this->assertStringContainsString('/Body 12 Tf', $bytes);
        $this->assertStringContainsString('/Fade gs', $bytes);
        $this->assertStringContainsString('/Logo Do', $bytes);
        $this->assertStringContainsString('/Font << /Body 5 0 R >>', $bytes);
        $this->assertStringContainsString('/XObject << /Logo 6 0 R >>', $bytes);
        $this->assertStringContainsString('/ExtGState << /Fade 7 0 R >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateAlpha(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /ca 0.5 /CA 0.75 >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setFillAlpha(0.2)
            ->setStrokeAlpha(0.9)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /ca 0.2 /CA 0.9 >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateAlpha(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /ca 0.4 /CA 0.6 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setFillAlpha(0.1)
            ->setStrokeAlpha(0.8)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /ca 0.1 /CA 0.8 >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateBlendMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /BM /Normal >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlendMode('Multiply')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /BM /Multiply >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateBlendMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BM /Screen >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlendMode('/Overlay')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /BM /Overlay >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateOverprint(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /OP false /op false /OPM 0 >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeOverprint()
            ->setFillOverprint()
            ->setOverprintMode(1)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /OP true /op true /OPM 1 >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateOverprint(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /OP true /op true /OPM 1 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeOverprint(false)
            ->setFillOverprint(false)
            ->setOverprintMode(0)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /OP false /op false /OPM 0 >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateFlags(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /SA false /AIS false /TK true >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeAdjustment()
            ->setAlphaIsShape()
            ->setTextKnockout(false)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /SA true /AIS true /TK false >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateFlags(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /SA true /AIS true /TK false >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeAdjustment(false)
            ->setAlphaIsShape(false)
            ->setTextKnockout()
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /SA false /AIS false /TK true >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateQualityControls(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /RI /RelativeColorimetric /FL 1 /SM 0.2 >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateRenderingIntent('Perceptual')
            ->setGraphicsStateFlatness(0.5)
            ->setSmoothnessTolerance(0.8)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /RI /Perceptual /FL 0.5 /SM 0.8 >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateQualityControls(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /RI /AbsoluteColorimetric /FL 5 /SM 0.9 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateRenderingIntent('/Saturation')
            ->setGraphicsStateFlatness(2)
            ->setSmoothnessTolerance(0.1)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /RI /Saturation /FL 2 /SM 0.1 >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateLineStyleControls(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /LW 1 /LC 0 /LJ 0 /ML 10 >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateLineWidth(2.5)
            ->setGraphicsStateLineCap(1)
            ->setGraphicsStateLineJoin(2)
            ->setGraphicsStateMiterLimit(4)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /LW 2.5 /LC 1 /LJ 2 /ML 4 >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateLineStyleControls(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /LW 4 /LC 2 /LJ 1 /ML 20 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateLineWidth(0)
            ->setGraphicsStateLineCap(0)
            ->setGraphicsStateLineJoin(1)
            ->setGraphicsStateMiterLimit(6)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /LW 0 /LC 0 /LJ 1 /ML 6 >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateDashPattern(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /D [[3 1] 2] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateDashPattern([4, 2.5, 1], 0.5)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /D [[4 2.5 1] 0.5] >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateDashPattern(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /D [[5 2] 1] >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateDashPattern([], 0)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /D [[] 0] >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateColorGenerationModes(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /BG /Default /UCR /Default /TR /Default /HT /Default >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode('Identity')
            ->setUndercolorRemovalMode('/Remove')
            ->setTransferFunctionMode('Linear')
            ->setHalftoneMode('News')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /BG /Identity /UCR /Remove /TR /Linear /HT /News >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateColorGenerationModes(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BG /Old /UCR /Old /TR /Old /HT /Old >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode('/Keep')
            ->setUndercolorRemovalMode('Soft')
            ->setTransferFunctionMode('Identity')
            ->setHalftoneMode('Fine')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /BG /Keep /UCR /Soft /TR /Identity /HT /Fine >> >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteReferencedGraphicsStateSecondGenerationColorModes(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 5 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
            5 => '<< /Type /ExtGState /BG2 /Default /UCR2 /Default /TR2 /Default >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode2('Identity')
            ->setUndercolorRemovalMode2('/Refined')
            ->setTransferFunctionMode2('Linear')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/GS1 gs', $bytes);
        $this->assertStringContainsString('<< /Type /ExtGState /BG2 /Identity /UCR2 /Refined /TR2 /Linear >>', $bytes);
    }

    public function testImportedPageEditorCanRewriteInlineGraphicsStateSecondGenerationColorModes(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BG2 /Old /UCR2 /Old /TR2 /Old >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode2('/Keep')
            ->setUndercolorRemovalMode2('Soft')
            ->setTransferFunctionMode2('Identity')
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('/ExtGState << /GS1 << /Type /ExtGState /BG2 /Keep /UCR2 /Soft /TR2 /Identity >> >>', $bytes);
    }

    public function testImportedPageEditorRejectsInvalidAlpha(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /ca 0.4 /CA 0.6 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setStrokeAlpha(1.5);
    }

    public function testImportedPageEditorRejectsEmptyBlendMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BM /Screen >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlendMode('   ');
    }

    public function testImportedPageEditorRejectsInvalidOverprintMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /OP true /op true /OPM 1 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setOverprintMode(2);
    }

    public function testImportedPageEditorRejectsEmptyGraphicsStateRenderingIntent(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /RI /Perceptual >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateRenderingIntent('   ');
    }

    public function testImportedPageEditorRejectsInvalidGraphicsStateFlatness(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /FL 5 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateFlatness(101);
    }

    public function testImportedPageEditorRejectsInvalidSmoothnessTolerance(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /SM 0.4 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setSmoothnessTolerance(1.5);
    }

    public function testImportedPageEditorRejectsInvalidGraphicsStateLineStyleControls(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /LW 1 /LC 0 /LJ 0 /ML 10 >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateLineCap(3);
    }

    public function testImportedPageEditorRejectsInvalidGraphicsStateDashPattern(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /D [[3 1] 2] >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setGraphicsStateDashPattern([3, -1], 0);
    }

    public function testImportedPageEditorRejectsEmptyGraphicsStateColorGenerationMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BG /Default >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode('   ');
    }

    public function testImportedPageEditorRejectsEmptyGraphicsStateSecondGenerationColorMode(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] /Resources << /ExtGState << /GS1 << /Type /ExtGState /BG2 /Default >> >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 15 >>\nstream\n/GS1 gs\nendstream",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new Importer())
            ->loadString($pdf)
            ->pages()
            ->page(1)
            ->setBlackGenerationMode2('   ');
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

    private function svgFixture(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">
  <rect x="2" y="2" width="16" height="16" fill="#2f4fe0"/>
</svg>
SVG;
    }
}
