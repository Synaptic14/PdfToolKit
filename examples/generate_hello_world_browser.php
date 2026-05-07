<?php

declare(strict_types=1);

use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;

require dirname(__DIR__) . '/vendor/autoload.php';

$downloadName = 'generated-hello-world.pdf';
$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'document.pdf';

$bytes = Pdf::new()
    ->metadata(
        title: 'Hello World',
        author: 'PdfToolkit',
        subject: 'Generated PDF example',
        keywords: ['example', 'generated', 'hello-world'],
    )
    ->addPage()
    ->text(new TextRun('Hello from PdfToolkit', 72, 72, 24, Pdf::font('Helvetica', 'bold')))
    ->text(new TextRun('This PDF was generated from scratch.', 72, 112, 12))
    ->line(72, 152, 280, 152, 1.5, Color::rgb(0.1, 0.1, 0.1))
    ->rectangle(new Rectangle(72, 176, 220, 64, fillColor: Color::rgb(0.93, 0.96, 1.0)))
    ->text(new TextRun('Top-origin coordinates: y = 0 is the top of the page.', 84, 198, 11))
    ->endPage()
    ->build()
    ->save();

header('Content-Type: application/pdf');
header(sprintf('Content-Disposition: inline; filename="%s"', $safeDownloadName));
header('Content-Length: ' . strlen($bytes));

echo $bytes;
