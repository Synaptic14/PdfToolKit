<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Graphics\Color;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Layout\TableCell;
use PdfToolkit\Layout\TableColumn;
use PdfToolkit\Layout\TableDataColumn;
use PdfToolkit\Layout\TableStyle;
use PdfToolkit\Layout\TextFrame;
use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Writer\WriteOptions;
use PHPUnit\Framework\TestCase;

final class StressWorkflowIntegrationTest extends TestCase
{
    public function testGeneratedMultiPageDocumentWithRepeatedInlineSvgRoundTripsAndReusesImageResource(): void
    {
        $bytes = $this->withSvgEnabled(function (): string {
            $builder = Pdf::new()->metadata(title: 'Stress Generated');

            for ($page = 1; $page <= 20; $page++) {
                $builder
                    ->addPage(width: 420, height: 300)
                    ->text(new TextRun(sprintf('Stress Page %d', $page), 36, 36, 16))
                    ->image(ImagePlacement::svgData($this->signatureSvgFixture(), 140, 90, 120, 36))
                    ->endPage();
            }

            return $builder->build()->save();
        });

        $this->assertSame(1, substr_count($bytes, '/Subtype /Image'));

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame(20, $reloaded->report()->pageCount);
        $this->assertStringContainsString('Stress Page 20', $reloaded->save());
    }

    public function testLargeRecordTableStressWorkflowPaginatesAndRoundTrips(): void
    {
        $records = [];

        for ($index = 1; $index <= 300; $index++) {
            $records[] = [
                'name' => 'Record ' . $index,
                'count' => $index,
            ];
        }

        $bytes = Pdf::new()
            ->addPage(width: 300, height: 240)
            ->tableRecordsFrame(
                $records,
                [
                    new TableDataColumn('Name', 'name', new TableColumn(120)),
                    new TableDataColumn('Count', 'count', new TableColumn(80, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 20, 220, 180),
                TableStyle::padded(
                    4,
                    borderColor: Color::black(),
                    lineWidth: 0.5,
                    headerFillColor: Color::gray(0.9),
                    alternateRowFillColor: Color::gray(0.97),
                ),
            )
            ->endPage()
            ->build()
            ->save();

        $reloaded = Pdf::loadString($bytes);
        $pageCount = $reloaded->report()->pageCount;

        $this->assertGreaterThan(10, $pageCount);
        $this->assertStringContainsString('Record 300', $reloaded->save());
    }

    public function testEncryptedMultiPageStressWorkflowRoundTripsWithPassword(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension is required for encrypted stress workflow tests.');
        }

        $builder = Pdf::new()->metadata(title: 'Stress Encrypted');

        for ($page = 1; $page <= 12; $page++) {
            $builder
                ->addPage(width: 420, height: 300)
                ->text(new TextRun(sprintf('Encrypted Stress Page %d', $page), 36, 36, 16))
                ->flowText(
                    str_repeat("Stress paragraph {$page}. ", 35),
                    36,
                    72,
                    320,
                    fontSize: 11,
                    lineHeight: 1.2,
                )
                ->endPage();
        }

        $bytes = $builder->build()->save(options: new WriteOptions(
            userPassword: 'stress-user',
            ownerPassword: 'stress-owner',
            encryptionRevision: 5,
            encryptionMethod: 'AESV3',
            compressStreams: true,
        ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringNotContainsString('Encrypted Stress Page 12', $bytes);

        $imported = Pdf::loadString($bytes, 'stress-user');

        $this->assertSame(12, $imported->report()->pageCount);
        $this->assertSame('AESV3', $imported->report()->security?->algorithm());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('Encrypted Stress Page 12', $roundTripped);
        $this->assertSame(12, Pdf::loadString($roundTripped)->report()->pageCount);
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
