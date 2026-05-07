<?php

declare(strict_types=1);

use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php examples/generate_hello_world.php <output.pdf>\n");
    exit(1);
}

$outputPath = $argv[1];

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
    ->save($outputPath);

fwrite(STDOUT, sprintf("Wrote generated PDF to %s (%d bytes)\n", $outputPath, strlen($bytes)));
