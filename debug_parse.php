<?php
require __DIR__ . '/vendor/autoload.php';
$contents = file_get_contents(__DIR__ . '/f1099msc-filled-debug.pdf');
$parser = new PdfToolkit\Parser\PdfParser();
$ref = new ReflectionClass($parser);
$method = $ref->getMethod('parseCrossReferenceData');
$method->setAccessible(true);
[$offsets, $compressed, $trailer, $warnings] = $method->invoke($parser, $contents);
$repo = new PdfToolkit\Parser\PdfObjectRepository($contents, $offsets, $compressed);
$nums = array_unique(array_merge(array_keys($offsets), array_keys($compressed)));
sort($nums);
foreach ($nums as $n) {
    try {
        $repo->get(new PdfToolkit\Parser\PdfReference($n, 0));
    } catch (Throwable $e) {
        echo "object {$n}: ".get_class($e).": ".$e->getMessage()."\n";
        $offset = $offsets[$n] ?? null;
        if (is_int($offset)) {
            echo substr($contents, $offset, 1200), "\n";
        } else {
            var_export($compressed[$n] ?? null);
            echo "\n";
        }
        exit(1);
    }
}
echo "all objects parsed\n";
