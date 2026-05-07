<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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

runScenario('generated-svg-pages', static function (): array {
    $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
    putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

    try {
        $builder = Pdf::new()->metadata(title: 'Stress Generated');

        for ($page = 1; $page <= 20; $page++) {
            $builder
                ->addPage(width: 420, height: 300)
                ->text(new TextRun(sprintf('Stress Page %d', $page), 36, 36, 16))
                ->image(ImagePlacement::svgData(signatureSvgFixture(), 140, 90, 120, 36))
                ->endPage();
        }

        $bytes = $builder->build()->save();
        $reloaded = Pdf::loadString($bytes);

        return [
            'bytes' => strlen($bytes),
            'pages' => $reloaded->report()->pageCount,
            'imageObjects' => substr_count($bytes, '/Subtype /Image'),
        ];
    } finally {
        if ($previous === false) {
            putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        } else {
            putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
        }
    }
});

runScenario('record-table-pagination', static function (): array {
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

    return [
        'bytes' => strlen($bytes),
        'pages' => $reloaded->report()->pageCount,
    ];
});

runScenario('encrypted-multipage-roundtrip', static function (): array {
    if (!extension_loaded('openssl')) {
        return ['skipped' => 'OpenSSL extension is not available.'];
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

    $imported = Pdf::loadString($bytes, 'stress-user');
    $roundTripped = $imported->save();

    return [
        'encryptedBytes' => strlen($bytes),
        'roundTripBytes' => strlen($roundTripped),
        'pages' => $imported->report()->pageCount,
        'algorithm' => $imported->report()->security?->algorithm(),
    ];
});

echo "\nStress run completed successfully.\n";

/**
 * @param callable(): array<string, scalar|null> $scenario
 */
function runScenario(string $name, callable $scenario): void
{
    gc_collect_cycles();
    $baselinePeak = memory_get_peak_usage(true);
    $start = microtime(true);

    $result = $scenario();

    $elapsed = microtime(true) - $start;
    $peak = memory_get_peak_usage(true);
    $peakDelta = max(0, $peak - $baselinePeak);

    echo "== {$name} ==\n";

    foreach ($result as $key => $value) {
        echo $key . ': ' . (string) $value . "\n";
    }

    echo 'elapsed_ms: ' . number_format($elapsed * 1000, 2, '.', '') . "\n";
    echo 'peak_memory_delta_mb: ' . number_format($peakDelta / 1048576, 2, '.', '') . "\n\n";
}

function signatureSvgFixture(): string
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
