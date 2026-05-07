<?php

declare(strict_types=1);

use PdfToolkit\Core\Page;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Import\ImportedDocument;
use PdfToolkit\Pdf;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\TextRun;

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

/*
|--------------------------------------------------------------------------
| Edit These Values
|--------------------------------------------------------------------------
|
| This example mirrors the legacy TCPDF/FPDI workflow from the benchmark
| controller:
| - import a multi-page PDF template
| - overlay fixed-position text and checkmarks
| - render raw in-memory SVG signatures
| - append generated beneficiary pages
|
| Important:
| - set $templatePath to the external application template PDF you want to use
| - if you want raw SVG signatures, enable trusted SVG rasterization with:
|     PDFTOOLKIT_ENABLE_SVG_MAGICK=1
| - the coordinates below are based on the legacy controller's millimeter
|   placement model and are converted to PDF points for PdfToolkit
|
*/

$templatePath = __DIR__.'/chubb_application_form.pdf';
$downloadName = 'application.pdf';
$signatureFontPath = __DIR__.'/GreatVibes-Regular.ttf'; // Optional TTF/TTC path for typed signatures, e.g. __DIR__ . '/fonts/GreatVibes-Regular.ttf'

$application = [
    'insured' => [
        'name' => 'Jane Applicant',
        'gender' => 'Female',
        'dob' => '05/17/1984',
        'address' => '100 Main Street',
        'city' => 'Austin',
        'state' => 'TX',
        'zip' => '78701',
        'phone' => '(512) 555-0100',
        'policy_number' => 'GL-1001001',
        'owner_is_not_insured' => true,
        'owner' => [
            'name' => 'John Owner',
            'dob' => '09/24/1979',
            'address' => '500 Oak Avenue',
            'city' => 'Dallas',
            'state' => 'TX',
            'zip' => '75201',
            'phone' => '(214) 555-0123',
            'relationship' => 'Spouse',
        ],
    ],
    'children' => [
        [
            'name' => 'Chris Applicant',
            'relationship' => 'Child',
            'gender' => 'Male',
            'dob' => '03/14/2012',
        ],
        [
            'name' => 'Taylor Applicant',
            'relationship' => 'Child',
            'gender' => 'Female',
            'dob' => '10/02/2015',
        ],
    ],
    'answers' => [
        'page1_resident_or_citizen' => true,
        'page2_question_6033' => true,
        'page2_question_6051' => false,
        'page2_child_rider' => true,
        'page2_question_6035' => false,
        'page2_question_6036' => true,
        'page2_question_6037' => false,
        'page3_question_6038' => true,
        'page3_question_6039' => false,
        'page4_electronic_submission' => true,
        'page4_electronic_delivery' => true,
    ],
    'premium' => [
        'total_premium' => '125.00',
        'effective_date' => '2026-06-01',
    ],
    'signatures' => [
        'insured' => [
            'name' => 'Jane Applicant',
            'svg' => <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="320" height="60" viewBox="0 0 320 60">
  <path d="M20 42 C40 8, 75 10, 96 36 S145 55, 168 18 S222 8, 246 36 S286 52, 300 28"
        fill="none" stroke="#2f4fe0" stroke-width="4" stroke-linecap="round"/>
</svg>
SVG,
            'city' => 'Austin',
            'state' => 'TX',
            'signed_on' => '2026-05-06',
        ],
        'agent' => [
            'name' => 'Alex Agent',
            'svg' => null,
        ],
    ],
    'agent' => [
        'name' => 'Benchmark Administration',
        'writing_number' => 'WR-778899',
        'signed_on' => '2026-05-06',
    ],
    'primary_beneficiaries' => [
        [
            'name' => 'Pat Primary',
            'address' => '10 First Street',
            'city' => 'Houston',
            'state' => 'TX',
            'zip' => '77002',
            'phone' => '(713) 555-0110',
            'dob' => '01/11/1980',
            'relationship' => 'Spouse',
            'percentage' => 100,
        ],
    ],
    'contingent_beneficiaries' => [
        [
            'name' => 'Morgan Contingent',
            'address' => '20 Second Street',
            'city' => 'San Antonio',
            'state' => 'TX',
            'zip' => '78205',
            'phone' => '(210) 555-0112',
            'dob' => '07/04/1988',
            'relationship' => 'Sibling',
            'percentage' => 100,
        ],
    ],
];

$safeDownloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $downloadName) ?: 'application.pdf';

if (!is_file($templatePath)) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "Template PDF not found.\n";
    echo "Update \$templatePath in " . basename(__FILE__) . " to point at your real application template.\n";
    echo "Current value: {$templatePath}\n";
    exit;
}

$imported = Pdf::load($templatePath);

if ($imported->report()->pageCount < 4) {
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    echo "This example expects a template with at least 4 pages.\n";
    echo "Current template page count: {$imported->report()->pageCount}\n";
    exit;
}

$signatureFont = buildSignatureFont($signatureFontPath);

fillTemplatePage1($imported, $application);
fillTemplatePage2($imported, $application);
fillTemplatePage3($imported, $application);
fillTemplatePage4($imported, $application, $signatureFont);
appendPrimaryBeneficiaryPage($imported, $application, $signatureFont);

if ($application['contingent_beneficiaries'] !== []) {
    appendContingentBeneficiaryPage($imported, $application, $signatureFont);
}

$bytes = $imported->save();

header('Content-Type: application/pdf');
header(sprintf('Content-Disposition: inline; filename="%s"', $safeDownloadName));
header('Content-Length: ' . strlen($bytes));

echo $bytes;

function buildSignatureFont(?string $signatureFontPath): ?FontReference
{
    if ($signatureFontPath === null || $signatureFontPath === '') {
        return null;
    }

    if (!is_file($signatureFontPath)) {
        throw new RuntimeException(sprintf('Signature font not found: %s', $signatureFontPath));
    }

    return Pdf::trueTypeFont($signatureFontPath, 'SignatureFont');
}

function fillTemplatePage1(ImportedDocument $imported, array $application): void
{
    $page = $imported->document()->page(0);
    $insured = $application['insured'];
    $owner = $insured['owner'];

    addMark($page, 31.5, 27.5);
    addText($page, 7, 48, $insured['name']);
    addMark($page, 128, $insured['gender'] === 'Male' ? 44 : 48.5);
    addText($page, 149, 48, $insured['dob']);
    addText($page, 7, 57.5, $insured['address']);
    addText($page, 7, 67.5, $insured['city']);
    addText($page, 149, 67.5, $insured['phone']);
    addText($page, 7, 77.5, $insured['state']);
    addText($page, 28, 77.5, $insured['zip']);
    addYesNoMark($page, $application['answers']['page1_resident_or_citizen'], 176.5, 197.0, 109.0);

    if ($insured['owner_is_not_insured']) {
        addText($page, 7, 128, $owner['name']);
        addText($page, 149, 128, $owner['dob']);
        addText($page, 7, 137.5, $owner['address']);
        addText($page, 7, 148, $owner['city']);
        addText($page, 149, 148, $owner['phone']);
        addText($page, 7, 158, $owner['state']);
        addText($page, 28, 158, $owner['zip']);
        addText($page, 130, 167, 'Relationship: ' . $owner['relationship']);
    }

    addAlignedText($page, 149, 250, 59, $insured['policy_number'], align: 'center');
}

function fillTemplatePage2(ImportedDocument $imported, array $application): void
{
    $page = $imported->document()->page(1);
    $top = 30.5;

    foreach ($application['children'] as $child) {
        addText($page, 12, $top, $child['name']);
        addText($page, 61, $top, $child['relationship']);
        addMark($page, 84.5, $child['gender'] === 'Male' ? $top - 2.0 : $top + 2.2);
        addText($page, 105, $top, $child['dob']);
        $top += 9.8;
    }

    addYesNoMark($page, $application['answers']['page2_question_6033'], 176.5, 197.0, 134.0);
    addYesNoMark($page, $application['answers']['page2_question_6051'], 176.5, 197.0, 144.0);
    addMark($page, 7.5, 175.5);

    if ($application['answers']['page2_child_rider']) {
        addMark($page, 14.0, 186.0);
    }

    addYesNoMark($page, $application['answers']['page2_question_6035'], 193.0, 200.0, 226.0);
    addYesNoMark($page, $application['answers']['page2_question_6036'], 193.0, 200.0, 240.5);
    addYesNoMark($page, $application['answers']['page2_question_6037'], 193.0, 200.0, 255.0);
}

function fillTemplatePage3(ImportedDocument $imported, array $application): void
{
    $page = $imported->document()->page(2);

    addYesNoMark($page, $application['answers']['page3_question_6038'], 194.0, 201.0, 39.0);
    addYesNoMark($page, $application['answers']['page3_question_6039'], 194.0, 201.0, 61.0);

    addText($page, 7.5, 92.0, 'See Attachment');
    addText($page, 7.5, 135.0, 'See Attachment');

    addText($page, 42.0, 155.0, $application['premium']['total_premium']);
    addText($page, 162.0, 155.0, '0.00');
    addMark($page, 48.0, 164.0);

    $effectiveDay = substr(date('d', strtotime($application['premium']['effective_date'])), 0, 2);
    addAlignedText($page, 170.0, 173.0, 5.0, $effectiveDay[0] ?? '', align: 'center');
    addAlignedText($page, 175.0, 173.0, 5.0, $effectiveDay[1] ?? '', align: 'center');
}

function fillTemplatePage4(ImportedDocument $imported, array $application, ?FontReference $signatureFont): void
{
    $page = $imported->document()->page(3);

    addYesNoMark($page, $application['answers']['page4_electronic_submission'], 174.0, 194.0, 110.0);
    addYesNoMark($page, $application['answers']['page4_electronic_delivery'], 174.0, 194.0, 114.0);

    placeSignature(
        $page,
        $application['signatures']['insured']['name'],
        $application['signatures']['insured']['svg'],
        13.0,
        182.0,
        85.0,
        10.0,
        $signatureFont
    );

    addText($page, 37.0, 198.0, $application['signatures']['insured']['city']);
    addText($page, 120.0, 198.0, $application['signatures']['insured']['state']);

    $signedOn = date('m/d/Y', strtotime($application['signatures']['insured']['signed_on']));
    addText($page, 175.5, 198.0, substr($signedOn, 0, 2));
    addText($page, 184.0, 198.0, substr($signedOn, 3, 2));
    addText($page, 192.0, 198.0, substr($signedOn, 6, 4));

    addText($page, 29.0, 229.0, $application['agent']['name']);
    addText($page, 88.0, 228.0, $application['agent']['writing_number']);

    placeSignature(
        $page,
        $application['signatures']['agent']['name'],
        $application['signatures']['agent']['svg'],
        35.0,
        237.0,
        70.0,
        14.0,
        $signatureFont
    );

    addText($page, 20.0, 259.0, date('m/d/Y', strtotime($application['agent']['signed_on'])));
}

function appendPrimaryBeneficiaryPage(ImportedDocument $imported, array $application, ?FontReference $signatureFont): void
{
    appendBeneficiaryPage(
        $imported,
        'Primary Beneficiary Information',
        'Primary Beneficiary',
        $application['primary_beneficiaries']
    );
}

function appendContingentBeneficiaryPage(ImportedDocument $imported, array $application, ?FontReference $signatureFont): void
{
    appendBeneficiaryPage(
        $imported,
        'Contingent Beneficiary Information',
        'Contingent Beneficiary',
        $application['contingent_beneficiaries']
    );
}

function appendBeneficiaryPage(
    ImportedDocument $imported,
    string $title,
    string $itemLabel,
    array $beneficiaries,
): void {
    $page = new Page(612.0, 792.0);
    $imported->document()->addPage($page);

    addAlignedText($page, 0.0, 10.0, pointsFromMm(215.9), $title, fontSize: 16.0, align: 'center', font: Pdf::font('Courier', 'bold'));
    $page->addLine(new Line(mm(10), mm(18), mm(200), mm(18), 1.0));

    $y = 30.0;

    foreach ($beneficiaries as $index => $beneficiary) {
        if ($index > 0) {
            $y += 10.0;
        }

        addText($page, 10.0, $y, $itemLabel . ' ' . ($index + 1), fontSize: 13.0, font: Pdf::font('Courier', 'bold'));
        $y += 5.0;

        addLabeledValue($page, 10.0, $y, 'Name:', $beneficiary['name']);
        $y += 5.0;

        $address = $beneficiary['address'] . ' ' . $beneficiary['city'] . ', ' . $beneficiary['state'] . ' ' . $beneficiary['zip'];
        addLabeledValue($page, 10.0, $y, 'Address:', $address);
        $y += 5.0;

        addLabeledValue($page, 10.0, $y, 'Phone:', $beneficiary['phone']);
        $y += 5.0;

        addLabeledValue($page, 10.0, $y, 'Date of Birth:', $beneficiary['dob']);
        $y += 5.0;

        addLabeledValue($page, 10.0, $y, 'Relationship:', $beneficiary['relationship']);
        $y += 5.0;

        if (array_key_exists('percentage', $beneficiary)) {
            addLabeledValue($page, 10.0, $y, 'Percentage:', (string) $beneficiary['percentage'] . '%');
            $y += 5.0;
        }
    }
}

function addLabeledValue(Page $page, float $xMm, float $yMm, string $label, string $value): void
{
    addText($page, $xMm, $yMm, $label, font: Pdf::font('Courier', 'bold'));
    addText($page, $xMm + 20.0, $yMm, $value);
}

function placeSignature(
    Page $page,
    string $signerName,
    ?string $svg,
    float $xMm,
    float $yMm,
    float $widthMm,
    float $heightMm,
    ?FontReference $signatureFont,
): void {
    if ($svg !== null && trim($svg) !== '') {
        $svgMarkup = normalizeSvgMarkup($svg);

        if ($svgMarkup !== null) {
        $page->addImage(ImagePlacement::svgData(
            $svgMarkup,
            mm($xMm),
            mm($yMm),
            mm($widthMm),
            mm($heightMm),
        ));

        return;
        }
    }

    $font = $signatureFont ?? Pdf::font('Helvetica', 'italic');
    $page->addText(new TextRun(
        $signerName,
        mm($xMm),
        mm($yMm + 3.0),
        25.0,
        $font,
        new Color(66 / 255, 66 / 255, 244 / 255),
    ));
}

function addMark(Page $page, float $xMm, float $yMm): void
{
    $page->addText(new TextRun('X', mm($xMm), mm($yMm), 12.0, Pdf::font('Courier')));
}

function addYesNoMark(Page $page, bool $yes, float $yesXMm, float $noXMm, float $yMm): void
{
    addMark($page, $yes ? $yesXMm : $noXMm, $yMm);
}

function addText(
    Page $page,
    float $xMm,
    float $yMm,
    string $text,
    float $fontSize = 12.0,
    ?FontReference $font = null,
    ?Color $color = null,
): void {
    $page->addText(new TextRun($text, mm($xMm), mm($yMm), $fontSize, $font ?? Pdf::font('Courier'), $color));
}

function addAlignedText(
    Page $page,
    float $xMm,
    float $yMm,
    float $widthMm,
    string $text,
    float $fontSize = 12.0,
    string $align = 'left',
    ?FontReference $font = null,
    ?Color $color = null,
): void {
    $font ??= Pdf::font('Courier');
    $x = mm($xMm);
    $width = mm($widthMm);
    $textWidth = Pdf::measureText($text, $fontSize, $font);

    if ($align === 'center') {
        $x += max(0.0, ($width - $textWidth) / 2);
    } elseif ($align === 'right') {
        $x += max(0.0, $width - $textWidth);
    }

    $page->addText(new TextRun($text, $x, mm($yMm), $fontSize, $font, $color));
}

function mm(float $value): float
{
    return $value * 72.0 / 25.4;
}

function pointsFromMm(float $value): float
{
    return mm($value);
}

function normalizeSvgMarkup(string $value): ?string
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return null;
    }

    if (str_starts_with($trimmed, '<svg') || (str_starts_with($trimmed, '<?xml') && str_contains($trimmed, '<svg'))) {
        return $trimmed;
    }

    if (preg_match('#^data:image/svg\\+xml(;charset=[^;,]+)?(;base64)?,#i', $trimmed, $matches) === 1) {
        $payload = substr($trimmed, strlen($matches[0]));

        if (str_contains(strtolower($matches[0]), ';base64')) {
            $decoded = base64_decode($payload, true);

            return is_string($decoded) && $decoded !== '' ? trim($decoded) : null;
        }

        return trim(urldecode($payload));
    }

    $decoded = base64_decode($trimmed, true);

    if (is_string($decoded) && $decoded !== '' && str_contains($decoded, '<svg')) {
        return trim($decoded);
    }

    return null;
}
