<?php

declare(strict_types=1);

use PdfToolkit\Pdf;
use PdfToolkit\Tests\Support\CorpusFixtures;
use PdfToolkit\Tests\Support\ExternalPdfToolValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

$validator = new ExternalPdfToolValidator();
$failures = [];

foreach (CorpusFixtures::all() as $fixture) {
    echo "== {$fixture->name} ==\n";

    if (!is_file($fixture->path)) {
        $failures[] = "{$fixture->name}: fixture file not found";
        echo "missing fixture: {$fixture->path}\n\n";
        continue;
    }

    try {
        $imported = Pdf::load($fixture->path);
        $report = $imported->report();
        echo "loaded: version={$report->version} pages={$report->pageCount}\n";

        $bytes = $imported->save();
        $reloaded = Pdf::loadString($bytes);
        echo "round-trip: pages={$reloaded->report()->pageCount}\n";

        if (in_array('overlay', $fixture->workflows, true)) {
            $overlayBytes = $reloaded
                ->pages()
                ->page(1)
                ->overlayText('Corpus Overlay', x: 72, y: 72, fontSize: 16)
                ->done()
                ->done()
                ->save();

            echo 'overlay: ' . (str_contains($overlayBytes, 'Corpus Overlay') ? "ok\n" : "missing\n");
        }

        if (in_array('form-fill', $fixture->workflows, true)) {
            $fieldNames = $imported->form()->fieldNames();
            echo 'form fields: ' . count($fieldNames) . "\n";

            if ($fieldNames !== []) {
                $filledBytes = $imported
                    ->form()
                    ->setText($fieldNames[0], 'Corpus Fill')
                    ->regenerateAppearances()
                    ->done()
                    ->save();

                echo 'form fill: ' . (str_contains($filledBytes, 'Corpus Fill') ? "ok\n" : "missing\n");
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdftoolkit-corpus-');

        if ($tempPath !== false) {
            try {
                file_put_contents($tempPath, $bytes);
                $results = $validator->validate($tempPath);

                foreach ($results as $tool => $result) {
                    if ($result['available'] !== true) {
                        echo "{$tool}: unavailable\n";
                        continue;
                    }

                    echo "{$tool}: " . ($result['ok'] === true ? 'ok' : 'failed') . "\n";

                    if ($result['ok'] !== true) {
                        $failures[] = "{$fixture->name}: {$tool} failed";
                    }
                }
            } finally {
                @unlink($tempPath);
            }
        }
    } catch (Throwable $exception) {
        $failures[] = "{$fixture->name}: {$exception->getMessage()}";
        echo 'error: ' . $exception->getMessage() . "\n";
    }

    echo "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "Corpus failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Corpus run completed successfully.\n";
