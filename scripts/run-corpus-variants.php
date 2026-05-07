<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PdfToolkit\Pdf;
use PdfToolkit\Tests\Support\CorpusFixtures;
use PdfToolkit\Writer\WriteOptions;

foreach (CorpusFixtures::all() as $fixture) {
    echo '== ' . $fixture->name . " ==\n";

    $imported = Pdf::load($fixture->path);

    $compressed = $imported->save(options: new WriteOptions(compressStreams: true));
    echo 'compressed: pages=' . Pdf::loadString($compressed)->report()->pageCount . "\n";

    if (extension_loaded('openssl')) {
        $encrypted = $imported->save(options: new WriteOptions(
            userPassword: 'variant-user',
            ownerPassword: 'variant-owner',
            encryptionRevision: 5,
            encryptionMethod: 'AESV3',
        ));
        $encryptedReloaded = Pdf::loadString($encrypted, 'variant-user');
        echo 'encrypted: pages=' . $encryptedReloaded->report()->pageCount
            . ' algorithm=' . ($encryptedReloaded->report()->security?->algorithm() ?? 'none') . "\n";
    } else {
        echo "encrypted: skipped (openssl unavailable)\n";
    }

    if (in_array('form-fill', $fixture->workflows, true)) {
        $fieldNames = $imported->form()->fieldNames();
        $filled = $imported
            ->form()
            ->setText($fieldNames[0], 'Variant Fill')
            ->regenerateAppearances()
            ->done()
            ->save(options: new WriteOptions(compressStreams: true));
        echo 'form-fill+compressed: pages=' . Pdf::loadString($filled)->report()->pageCount . "\n";
    }

    echo "\n";
}

echo "Corpus variant run completed successfully.\n";
