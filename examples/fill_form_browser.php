<?php

declare(strict_types=1);

use PdfToolkit\Pdf;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Edit These Values
|--------------------------------------------------------------------------
|
| Load this file in your browser and it will import the source PDF, update
| the configured fillable form fields, and stream the modified PDF inline.
|
| Notes:
| - Use exact AcroForm field names from the source PDF.
| - Hierarchical names like "billing.city" are supported.
| - Checkbox fields default to the "Yes" on-state unless you provide a
|   different on-name in the $checkboxFields array.
|
*/

$sourcePath = __DIR__ . '/f1099msc.pdf';
$downloadName = 'filled-form-output.pdf';
$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'document.pdf';

$textFields = [
    // 'full_name' => 'Ada Lovelace',
    // 'billing.city' => 'Paris',
    // 'topmostSubform?.CopyA?.Box16_ReadOrder?.f1_22?' => 'Test',
];

$checkboxFields = [
    // 'accepted_terms' => true,
    // 'marketing_opt_in' => ['checked' => true, 'onName' => 'On'],
];

$regenerateAppearances = true;
$reconnectWidgetsToPages = false;
$flatten = false;

if (!is_file($sourcePath)) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Source PDF not found.\n";
    echo "Update \$sourcePath in " . basename(__FILE__) . " to point at a real PDF file.\n";
    echo "Current value: {$sourcePath}\n";
    exit;
}

try {
    $imported = Pdf::load($sourcePath);
    $form = $imported->form();

    foreach ($textFields as $name => $value) {
        $form->setText((string) $name, (string) $value);
    }

    foreach ($checkboxFields as $name => $config) {
        if (is_array($config)) {
            $form->setCheckbox(
                (string) $name,
                (bool) ($config['checked'] ?? false),
                (string) ($config['onName'] ?? 'Yes')
            );

            continue;
        }

        $form->setCheckbox((string) $name, (bool) $config);
    }

    if ($reconnectWidgetsToPages) {
        $form->reconnectWidgetsToPages();
    }

    if ($regenerateAppearances) {
        $form->regenerateAppearances();
    }

    if ($flatten) {
        $form->flatten();
    }

    $bytes = $form
        ->done()
        ->save();
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Unable to fill PDF form.\n\n";
    echo $e::class . ": " . $e->getMessage() . "\n";
    exit;
}

header('Content-Type: application/pdf');
header(sprintf('Content-Disposition: inline; filename="%s"', $safeDownloadName));
header('Content-Length: ' . strlen($bytes));

echo $bytes;
