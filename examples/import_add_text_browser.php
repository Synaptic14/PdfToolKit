<?php

declare(strict_types=1);

use PdfToolkit\Pdf;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Edit These Values
|--------------------------------------------------------------------------
|
| Set $sourcePath to an existing PDF on disk. When you load this file in
| the browser, it will import that PDF, add the text below to page 1, and
| stream the modified PDF back inline.
|
*/

$sourcePath = __DIR__ . '/f1099msc.pdf';
$text = 'Imported with PdfToolkit';
$x = 72;
$y = 0; // Distance from the top of page 1.
$fontSize = 18;
$downloadName = 'imported-output.pdf';
$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'document.pdf';

if (!is_file($sourcePath)) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Source PDF not found.\n";
    echo "Update \$sourcePath in " . basename(__FILE__) . " to point at a real PDF file.\n";
    echo "Current value: {$sourcePath}\n";
    exit;
}

$bytes = Pdf::load($sourcePath)
    ->pages()
    ->page(1)
    ->overlayText($text, x: $x, y: $y, fontSize: $fontSize)
    ->done()
    ->done()
    ->save();

header('Content-Type: application/pdf');
header(sprintf('Content-Disposition: inline; filename="%s"', $safeDownloadName));
header('Content-Length: ' . strlen($bytes));

echo $bytes;
