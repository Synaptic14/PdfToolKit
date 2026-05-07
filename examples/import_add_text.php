<?php

declare(strict_types=1);

use PdfToolkit\Pdf;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php examples/import_add_text.php <source.pdf> <output.pdf> [text]\n");
    exit(1);
}

$sourcePath = $argv[1];
$outputPath = $argv[2];
$text = $argv[3] ?? 'Imported with PdfToolkit';

if (!is_file($sourcePath)) {
    fwrite(STDERR, sprintf("Source PDF not found: %s\n", $sourcePath));
    exit(1);
}

$imported = Pdf::load($sourcePath);

$imported
    ->pages()
    ->page(1)
    ->overlayText($text, x: 72, y: 72, fontSize: 18)
    ->done()
    ->save($outputPath);

fwrite(STDOUT, sprintf("Wrote modified PDF to %s\n", $outputPath));
